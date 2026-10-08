<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\Exceptions\MollieException;
use Mollie\Api\Http\Data\Money as MollieMoney;
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
     * The checkout languages Mollie accepts. A locale outside this list
     * would make Mollie refuse the payment, so it is never sent.
     *
     * @see https://docs.mollie.com/reference/create-payment
     */
    public const LOCALES = [
        'ca_ES',
        'cs_CZ',
        'da_DK',
        'de_AT',
        'de_CH',
        'de_DE',
        'de_LU',
        'el_GR',
        'en_BE',
        'en_GB',
        'en_NL',
        'en_US',
        'es_ES',
        'fi_FI',
        'fr_BE',
        'fr_FR',
        'fr_LU',
        'hu_HU',
        'is_IS',
        'it_IT',
        'lt_LT',
        'lv_LV',
        'nb_NO',
        'nl_BE',
        'nl_NL',
        'pl_PL',
        'pt_PT',
        'sk_SK',
        'sl_SI',
        'sv_SE',
        'tr_TR',
    ];

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
                'metadata' => [
                    'order_id' => (int) $order->id,
                    'reference' => (string) $order->order_id,
                ],
                ...$this->cancelUrl($order),
                ...$this->locale(),
                ...$this->methods(),
                ...$this->billingAddress($order),
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
     * A Mollie amount read as integer cents — the package's money.
     * Absent amounts (Mollie omits the zero ones) read as nothing.
     *
     * @param MollieMoney|null $amount
     * @return int
     *
     * @throws \Tnt\Ecommerce\NotAnAmount If the value is not an exact amount.
     */
    private function cents(?MollieMoney $amount): int
    {
        return $amount === null ? 0 : Money::fromDecimal($amount->value);
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
        return $this->withOrder(
            $this->configuredUrl('mollie.redirect_url'),
            $order
        );
    }

    /**
     * The configured cancel page, as the return page has it, or nothing:
     * left out, Mollie sends a visitor who cancels to the return page.
     * Either way the webhook, not the page, decides the payment's state.
     *
     * @param Order $order
     * @return array{cancelUrl?: string}
     */
    private function cancelUrl(Order $order): array
    {
        $url = $this->configuredUrl('mollie.cancel_url');

        return $url === ''
            ? []
            : ['cancelUrl' => $this->withOrder($url, $order)];
    }

    /**
     * The configured checkout language, or nothing: left out, Mollie goes by
     * the browser. `mollie.locale` is one locale, or a map from the page's
     * language (`Response::$language`) to one. Anything Mollie would refuse
     * is left out too — a wrong locale must not cost the payment.
     *
     * @return array{locale?: string}
     */
    private function locale(): array
    {
        $configured = $this->config->get('mollie.locale');

        $locale = is_array($configured)
            ? $configured[(string) \dry\http\Response::$language] ?? null
            : $configured;

        return in_array($locale, self::LOCALES, true)
            ? ['locale' => $locale]
            : [];
    }

    /**
     * The configured payment methods, or nothing: left out, Mollie offers
     * every method the profile has enabled. One method skips Mollie's
     * selection screen; a list narrows it. Not checked against Mollie's
     * vocabulary — which methods exist is the profile's business.
     *
     * @return array{method?: string|list<string>}
     */
    private function methods(): array
    {
        $configured = $this->config->get('mollie.methods');

        if (is_string($configured)) {
            $configured = [$configured];
        }

        if (!is_array($configured)) {
            return [];
        }

        $methods = array_values(
            array_filter(
                $configured,
                fn($method): bool => is_string($method) && $method !== ''
            )
        );

        return match (count($methods)) {
            0 => [],
            1 => ['method' => $methods[0]],
            default => ['method' => $methods],
        };
    }

    /**
     * The order's frozen identity and billing address, so Mollie's checkout
     * knows who pays — bank transfer mails its instructions to the email.
     * Each field goes only if Mollie would take it, and the address only
     * with what Mollie requires of one: an email, or a whole postal address.
     * A badly typed order must not cost the payment.
     *
     * @param Order $order
     * @return array{billingAddress?: array<string, string>}
     */
    private function billingAddress(Order $order): array
    {
        $address = array_filter(
            [
                'givenName' => $this->personName($order->getFirstName()),
                'familyName' => $this->personName($order->getLastName()),
                'organizationName' => trim($order->getCompanyName()),
                'email' => filter_var(
                    trim($order->getEmail()),
                    FILTER_VALIDATE_EMAIL
                ),
                ...$this->postalAddress($order),
            ],
            fn($value): bool => is_string($value) && $value !== ''
        );

        $complete =
            isset($address['email']) || isset($address['streetAndNumber']);

        return $complete ? ['billingAddress' => $address] : [];
    }

    /**
     * The billing address in Mollie's fields, or nothing unless it is
     * whole: street, postal code, city and an ISO 3166-1 alpha-2 country.
     *
     * @param Order $order
     * @return array<string, string>
     */
    private function postalAddress(Order $order): array
    {
        $billing = $order->getBillingAddress();

        $street = trim($billing->getStreet() . ' ' . $billing->getNumber());
        $postalCode = trim($billing->getPostalCode());
        $city = trim($billing->getCity());
        $country = strtoupper(trim($billing->getCountry()));

        if (
            $street === '' ||
            $postalCode === '' ||
            $city === '' ||
            preg_match('/^[A-Z]{2}$/', $country) !== 1
        ) {
            return [];
        }

        return [
            'streetAndNumber' => $street,
            'streetAdditional' => trim($billing->getBox()),
            'postalCode' => $postalCode,
            'city' => $city,
            'country' => $country,
        ];
    }

    /**
     * A name as Mollie takes one — two characters at least, not only
     * digits — or ''.
     *
     * @param string $name
     * @return string
     */
    private function personName(string $name): string
    {
        $name = trim($name);

        return mb_strlen($name) >= 2 && !ctype_digit($name) ? $name : '';
    }

    /**
     * A URL with the order appended as `order=`.
     *
     * @param string $url
     * @param Order $order
     * @return string
     */
    private function withOrder(string $url, Order $order): string
    {
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
