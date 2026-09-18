<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\Exceptions\MollieException;
use Mollie\Api\Resources\Chargeback;
use Mollie\Api\Resources\Payment;
use Mollie\Api\Resources\Refund;
use Mollie\Api\Types\PaymentQuery;
use Oak\Contracts\Config\RepositoryInterface;
use Tnt\Ecommerce\Contracts\OrderInterface;
use Tnt\Ecommerce\Contracts\PaymentGatewayInterface;
use Tnt\Ecommerce\Model\Order;
use Tnt\Ecommerce\Money;
use Tnt\Ecommerce\Payment\EntryKind;
use Tnt\Ecommerce\Payment\Movement;
use Tnt\Ecommerce\Payment\PaymentOutcome;
use Tnt\Ecommerce\Payment\PaymentRedirect;
use Tnt\Ecommerce\Payment\PaymentRefused;
use Tnt\Ecommerce\Payment\PaymentReport;
use Tnt\Ecommerce\Payment\PaymentSettled;
use Tnt\Ecommerce\Payment\PaymentStatus;
use Tnt\Mollie\Contracts\MollieClientFactoryInterface;

/**
 * The Mollie gateway on dry-ecommerce's payment ledger: pay() creates the
 * Mollie payment and says so, reportOf() tells what Mollie knows about it.
 * It reports and the package writes — no `payment_id`, no `payment_status`,
 * no events, no redirect. See docs/gateway.md.
 */
class MolliePayment implements PaymentGatewayInterface
{
    /**
     * The name every ledger entry is filed under. Never change it: the
     * entries a shop already has would be orphaned.
     */
    private const PROVIDER = 'mollie';

    /**
     * @param RepositoryInterface $config
     * @param MollieClientFactoryInterface $clients
     */
    public function __construct(
        private RepositoryInterface $config,
        private MollieClientFactoryInterface $clients
    ) {}

    /**
     * @return string
     */
    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * Create the Mollie payment for an order and answer where the visitor
     * must go. A zero total is settled on the spot, like NullPayment.
     *
     * @param OrderInterface $order
     * @return PaymentOutcome
     *
     * @throws \Random\RandomException If the system has no secure randomness.
     */
    public function pay(OrderInterface $order): PaymentOutcome
    {
        if ($order->getTotal() <= 0) {
            return $this->settleForFree();
        }

        // The return URL and description need the package's model; the
        // package only ever places those.
        if (!$order instanceof Order) {
            return new PaymentRefused();
        }

        try {
            // Built here, not injected, so a bad API key fails inside this try.
            $mollie = $this->clients->make();

            $molliePayment = $mollie->payments->create([
                'amount' => [
                    'currency' => 'EUR',
                    // The order's money is integer cents; Mollie wants
                    // '12.50' strings.
                    'value' => Money::toDecimal($order->getTotal()),
                ],
                'description' => (string) $order->order_id,
                'redirectUrl' => $this->returnUrl($order),
                'webhookUrl' => $this->configuredUrl('mollie.webhook_url'),
            ]);
        } catch (MollieException) {
            // The root of every Mollie failure; see docs/gateway.md.
            return new PaymentRefused();
        }

        $checkoutUrl = $molliePayment->getCheckoutUrl();

        if ($checkoutUrl === null) {
            // Nowhere to send the visitor: as dead as a refused payment.
            return new PaymentRefused($molliePayment->id);
        }

        return new PaymentRedirect($molliePayment->id, $checkoutUrl);
    }

    /**
     * What Mollie's API says about a payment now — never the webhook body,
     * which carries only the id. The whole story every time: the ledger
     * writes only what it does not hold yet.
     *
     * Failures are deliberately not caught here: the webhook wants them, so
     * the host can answer non-2xx and Mollie retries for hours.
     *
     * @param string $paymentId
     * @return PaymentReport
     *
     * @throws MollieException When Mollie cannot be reached or does not know
     *                         the payment, or the API key is not usable.
     * @throws \Tnt\Ecommerce\NotAnAmount If Mollie's amounts are unreadable.
     */
    public function reportOf(string $paymentId): PaymentReport
    {
        // Refunds and chargebacks ride along in the one call. A list, not
        // Mollie's comma string: the SDK drops the string without a word.
        $payment = $this->clients->make()->payments->get($paymentId, [
            'embed' => [
                PaymentQuery::EMBED_REFUNDS,
                PaymentQuery::EMBED_CHARGEBACKS,
            ],
        ]);

        return new PaymentReport(
            $payment->id,
            $this->statusFor($payment),
            $this->movementsOf($payment)
        );
    }

    /**
     * A zero total: nothing to charge, so a €0 capture under a minted id,
     * unique per placement so a re-placement is an attempt of its own.
     *
     * @return PaymentSettled
     *
     * @throws \Random\RandomException If the system has no secure randomness.
     */
    private function settleForFree(): PaymentSettled
    {
        $paymentId = 'free_' . bin2hex(random_bytes(8));

        return new PaymentSettled(
            new PaymentReport($paymentId, PaymentStatus::Paid, [
                new Movement(EntryKind::Captured, $paymentId, 0),
            ])
        );
    }

    /**
     * Mollie's status in the package's words. Never Refunded or
     * PartiallyRefunded: whether money went back is the ledger's arithmetic
     * over the movements.
     *
     * @param Payment $payment
     * @return PaymentStatus
     */
    private function statusFor(Payment $payment): PaymentStatus
    {
        return match (true) {
            // Mollie keeps a refunded or charged-back payment on paid.
            $payment->isPaid() => PaymentStatus::Paid,
            $payment->isFailed() => PaymentStatus::Failed,
            $payment->isCanceled() => PaymentStatus::Canceled,
            $payment->isExpired() => PaymentStatus::Expired,
            // open, pending — and authorized, where the money is only
            // reserved and a capture can still fail or be voided.
            default => PaymentStatus::Pending,
        };
    }

    /**
     * Every money movement Mollie knows of for the payment.
     *
     * @param Payment $payment
     * @return list<Movement>
     *
     * @throws \Tnt\Ecommerce\NotAnAmount If Mollie's amounts are unreadable.
     */
    private function movementsOf(Payment $payment): array
    {
        $movements = [];

        // A paid report must carry its capture: the ledger decides paid
        // from the money, not from the word.
        if ($payment->isPaid()) {
            $movements[] = new Movement(
                EntryKind::Captured,
                $payment->id,
                $this->cents($payment->amount)
            );
        }

        /** @var iterable<Refund> $refunds */
        $refunds = $payment->_embedded->refunds ?? [];

        foreach ($refunds as $refund) {
            $kind = $this->refundKind($refund);

            if ($kind !== null) {
                $movements[] = new Movement(
                    $kind,
                    $refund->id,
                    $this->cents($refund->amount)
                );
            }
        }

        /** @var iterable<Chargeback> $chargebacks */
        $chargebacks = $payment->_embedded->chargebacks ?? [];

        foreach ($chargebacks as $chargeback) {
            $amount = $this->cents($chargeback->amount);

            // Still reported once reversed: the reversal needs it.
            $movements[] = new Movement(
                EntryKind::Chargeback,
                $chargeback->id,
                $amount
            );

            if ($chargeback->reversedAt !== null) {
                $movements[] = new Movement(
                    EntryKind::ChargebackReversed,
                    $chargeback->id,
                    $amount
                );
            }
        }

        return $movements;
    }

    /**
     * What a refund counts as, or null while it moved no money. Queued and
     * pending can still be canceled; canceled never happened. A failed one
     * is reported as reversed even if its refund was never seen — the
     * ledger drops a reversal whose counterpart it does not hold.
     *
     * @param Refund $refund
     * @return EntryKind|null
     */
    private function refundKind(Refund $refund): ?EntryKind
    {
        return match (true) {
            $refund->isProcessing(),
            $refund->isTransferred()
                => EntryKind::Refunded,
            $refund->isFailed() => EntryKind::RefundReversed,
            default => null,
        };
    }

    /**
     * A Mollie amount object read as integer cents — the package's money.
     * Absent amounts (Mollie omits the zero ones) read as nothing.
     *
     * @param \stdClass|null $amount
     * @return int
     *
     * @throws \Tnt\Ecommerce\NotAnAmount If the value is not an exact amount.
     */
    private function cents(?\stdClass $amount): int
    {
        $value = $amount->value ?? null;

        return is_string($value) ? Money::fromDecimal($value) : 0;
    }

    /**
     * The configured return page with the order appended as `order=`, so
     * the page can find the order whose state it renders. Identification,
     * not authentication — the page still decides who may see what.
     *
     * @param Order $order
     * @return string
     */
    private function returnUrl(Order $order): string
    {
        $url = $this->configuredUrl('mollie.redirect_url');

        return $url .
            (str_contains($url, '?') ? '&' : '?') .
            'order=' .
            (int) $order->id;
    }

    /**
     * A URL from configuration, or '' when unset.
     *
     * @param string $key
     * @return string
     */
    private function configuredUrl(string $key): string
    {
        $configured = $this->config->get($key);

        return is_string($configured) ? $configured : '';
    }
}
