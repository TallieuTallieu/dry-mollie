<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
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
     * @param MollieApiClient $mollie
     * @param DispatcherInterface $dispatcher
     * @param RedirectorInterface $redirector
     */
    public function __construct(
        private RepositoryInterface $config,
        private MollieApiClient $mollie,
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
            $molliePayment = $this->mollie->payments->create([
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
        } catch (ApiException) {
            // The attempt never left the shop. Failed keeps the order
            // re-placeable, so the visitor can try again from the basket
            // that is still standing.
            $this->dispatcher->dispatch(
                PaymentFailed::class,
                new PaymentFailed($order)
            );

            return;
        }

        // The webhook's lookup key. Overwritten on a re-placed order: the
        // old payment is dead at Mollie and this attempt is the live one.
        $order->payment_id = $molliePayment->id;
        $order->save();

        $checkoutUrl = $molliePayment->getCheckoutUrl();

        if ($checkoutUrl !== null) {
            $this->redirector->redirect($checkoutUrl);
        }
    }

    /**
     * Where the money for a Mollie payment stands, per Mollie's API — never
     * per the webhook body, which carries only the id.
     *
     * @param string $paymentId
     * @return PaymentStatus
     *
     * @throws ApiException When Mollie cannot be reached or does not know
     *                      the payment.
     */
    public function statusOf(string $paymentId): PaymentStatus
    {
        $payment = $this->mollie->payments->get($paymentId);

        if ($payment->isPaid()) {
            // Mollie keeps a refunded or charged-back payment on paid; the
            // money that went back hangs off it as refunds/chargebacks.
            return $payment->hasRefunds() || $payment->hasChargebacks()
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
