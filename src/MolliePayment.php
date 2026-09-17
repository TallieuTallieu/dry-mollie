<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\Exceptions\MollieException;
use Mollie\Api\Resources\Payment;
use Oak\Contracts\Config\RepositoryInterface;
use Oak\Contracts\Dispatcher\DispatcherInterface;
use Tnt\Ecommerce\Contracts\OrderInterface;
use Tnt\Ecommerce\Contracts\PaymentGatewayInterface;
use Tnt\Ecommerce\Contracts\RedirectorInterface;
use Tnt\Ecommerce\Events\Order\Paid;
use Tnt\Ecommerce\Events\Order\PaymentFailed;
use Tnt\Ecommerce\Model\Order;
use Tnt\Ecommerce\Money;
use Tnt\Ecommerce\Payment\PaymentStatus;
use Tnt\Mollie\Contracts\MollieClientFactoryInterface;

/**
 * The Mollie gateway on dry-ecommerce's payment harness: pay() creates the
 * Mollie payment and redirects to its checkout, statusOf() asks Mollie's
 * API where the money stands. Dispatches events and never writes
 * `payment_status` — the package's listeners own that column. See
 * docs/gateway.md.
 */
class MolliePayment implements PaymentGatewayInterface
{
    /**
     * @param RepositoryInterface $config
     * @param MollieClientFactoryInterface $clients
     * @param DispatcherInterface $dispatcher
     * @param RedirectorInterface $redirector
     */
    public function __construct(
        private RepositoryInterface $config,
        private MollieClientFactoryInterface $clients,
        private DispatcherInterface $dispatcher,
        private RedirectorInterface $redirector
    ) {}

    /**
     * Create the Mollie payment for an order and redirect to its checkout.
     * A zero total is paid on the spot, like NullPayment.
     *
     * @param OrderInterface $order
     * @return void
     */
    public function pay(OrderInterface $order): void
    {
        // Like the package's own listeners: an order that is not the
        // package's model is left alone.
        if (!$order instanceof Order) {
            return;
        }

        if ($order->getTotal() <= 0) {
            $this->dispatcher->dispatch(Paid::class, new Paid($order));

            $this->redirector->redirect($this->returnUrl($order));

            return;
        }

        try {
            // Built here, not injected: a missing or malformed API key
            // throws while the client is built, and that failure belongs
            // in this block with the rest.
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
            // MollieException is the root of the lot: a refusal from the
            // API, a network failure, a timeout, a Mollie outage, a bad
            // key. None of them got the visitor to a checkout, so all of
            // them are the same failed attempt. Anything narrower would
            // throw out of pay() onto an error page, leaving a placed,
            // unpaid order behind.
            $this->reportAFailedAttempt($order);

            return;
        }

        $checkoutUrl = $molliePayment->getCheckoutUrl();

        if ($checkoutUrl === null) {
            // A payment with nowhere to send the visitor is a dead end:
            // saving its id would leave the order waiting on a payment
            // that can never be made. Treat it as the failed attempt it
            // is, and let the visitor try again.
            $this->reportAFailedAttempt($order);

            return;
        }

        // The webhook's lookup key. Overwritten on a re-placed order: the
        // old payment is dead at Mollie and this attempt is the live one.
        $order->payment_id = $molliePayment->id;
        $order->save();

        $this->redirector->redirect($checkoutUrl);
    }

    /**
     * Where the money for a Mollie payment stands, per Mollie's API — never
     * per the webhook body, which carries only the id.
     *
     * Failures are deliberately not caught here: the webhook wants them, so
     * the host can answer non-2xx and Mollie retries for hours.
     *
     * @param string $paymentId
     * @return PaymentStatus
     *
     * @throws MollieException When Mollie cannot be reached or does not know
     *                         the payment, or the API key is not usable.
     */
    public function statusOf(string $paymentId): PaymentStatus
    {
        $payment = $this->clients->make()->payments->get($paymentId);

        if ($payment->isPaid()) {
            // Mollie keeps a refunded or charged-back payment on paid; the
            // money that went back hangs off it as refunds/chargebacks.
            return $this->wasFullyReturned($payment)
                ? PaymentStatus::Refunded
                : PaymentStatus::Paid;
        }

        if ($payment->isFailed()) {
            return PaymentStatus::Failed;
        }

        if ($payment->isCanceled()) {
            return PaymentStatus::Canceled;
        }

        if ($payment->isExpired()) {
            return PaymentStatus::Expired;
        }

        // open, pending — and authorized, where the money is only reserved
        // and a capture can still fail or be voided: nothing is reported
        // until Mollie says paid. Pending dispatches no event.
        return PaymentStatus::Pending;
    }

    /**
     * Whether everything the customer paid has gone back to them.
     *
     * `hasRefunds()`/`hasChargebacks()` only say a link is there, so they
     * cannot tell a €1 refund on a €100 order from a full one. They are
     * not asked: the amounts are, in cents. It matters because `Refunded`
     * is terminal in dry-ecommerce — nothing may follow it — so a partial
     * refund must stay `Paid`.
     *
     * A refund over the payment (Mollie allows it, to reimburse return
     * shipping) counts as full, as does a full chargeback.
     *
     * @param Payment $payment
     * @return bool
     *
     * @throws \Tnt\Ecommerce\NotAnAmount If Mollie's amounts are unreadable.
     */
    private function wasFullyReturned(Payment $payment): bool
    {
        $paid = $this->cents($payment->amount);

        // Nothing was charged, so nothing can have gone back.
        if ($paid <= 0) {
            return false;
        }

        $returned =
            $this->cents($payment->amountRefunded) +
            $this->cents($payment->amountChargedBack);

        return $returned >= $paid;
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
     * The attempt never left the shop. `Failed` keeps the order
     * re-placeable, so the visitor can try again from the basket that is
     * still standing — and `payment_id` is dropped, because there is no
     * live payment to answer a webhook for.
     *
     * @param Order $order
     * @return void
     */
    private function reportAFailedAttempt(Order $order): void
    {
        if ($order->payment_id !== null) {
            $order->payment_id = null;
            $order->save();
        }

        $this->dispatcher->dispatch(
            PaymentFailed::class,
            new PaymentFailed($order)
        );
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
