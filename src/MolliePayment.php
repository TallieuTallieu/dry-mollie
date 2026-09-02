<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use dry\http\Response;
use dry\route\NotFound;
use dry\util\Helpers;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\MollieApiClient;
use Oak\Contracts\Config\RepositoryInterface;
use Oak\Contracts\Dispatcher\DispatcherInterface;
use Oak\Dispatcher\Facade\Dispatcher;
use Tnt\Ecommerce\Contracts\OrderInterface;
use Tnt\Ecommerce\Contracts\PaymentInterface;
use Tnt\Ecommerce\Events\Order\Paid;
use Tnt\Ecommerce\Events\Order\PaymentCanceled;
use Tnt\Ecommerce\Events\Order\PaymentExpired;
use Tnt\Ecommerce\Events\Order\PaymentFailed;
use Tnt\Ecommerce\Events\Order\PaymentRefunded;
use Tnt\Ecommerce\Model\Order;
use Tnt\Ecommerce\Money;
use Tnt\Ecommerce\Repository\OrderRepository;

/**
 * The 1.x gateway, ported just far enough to compile against dry-ecommerce
 * 4.x. Dispatches events and never writes `payment_status` — the package's
 * listeners own that column. The reimplementation on the payment harness
 * (PaymentGatewayInterface + PaymentWebhook) is the follow-up ticket.
 */
class MolliePayment implements PaymentInterface
{
    /**
     * @param RepositoryInterface $config
     * @param MollieApiClient $mollie
     * @param DispatcherInterface $dispatcher
     */
    public function __construct(
        private RepositoryInterface $config,
        private MollieApiClient $mollie,
        private DispatcherInterface $dispatcher
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

        $redirectUrl = $this->configuredRedirectUrl();

        if ($order->getTotal() <= 0) {
            $this->dispatcher->dispatch(Paid::class, new Paid($order));

            Response::redirect($redirectUrl, 302);

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
                'redirectUrl' =>
                    $redirectUrl . '?cancel=false&order=' . (int) $order->id,
                'cancelUrl' =>
                    $redirectUrl . '?cancel=true&order=' . (int) $order->id,
                'webhookUrl' => Helpers::abs_url('mollie-webhook/'),
            ]);

            // The webhook's lookup key. Overwritten on a re-placed order:
            // the old payment is dead at Mollie.
            $order->payment_id = $molliePayment->id;
            $order->save();

            $checkoutUrl = $molliePayment->getCheckoutUrl();

            if ($checkoutUrl !== null) {
                Response::redirect($checkoutUrl, 302);
            }
        } catch (ApiException) {
            $this->dispatcher->dispatch(
                PaymentFailed::class,
                new PaymentFailed($order)
            );
        }
    }

    /**
     * Process a Mollie webhook: fetch the payment, find its order, dispatch
     * the event its status maps to. An open payment reports nothing yet.
     *
     * @param MollieApiClient $mollieApiClient
     * @param string $paymentId
     * @return void
     *
     * @throws NotFound When no order carries the payment id.
     * @throws ApiException When the Mollie API cannot be reached.
     */
    public static function process(
        MollieApiClient $mollieApiClient,
        string $paymentId
    ): void {
        $molliePayment = $mollieApiClient->payments->get($paymentId);

        $order = OrderRepository::create()
            ->byPaymentId($molliePayment->id)
            ->firstOrNull();

        if ($order === null) {
            throw new NotFound();
        }

        if ($molliePayment->isOpen()) {
            return;
        }

        if ($molliePayment->isPaid()) {
            if ($molliePayment->hasRefunds()) {
                Dispatcher::dispatch(
                    PaymentRefunded::class,
                    new PaymentRefunded($order)
                );

                return;
            }

            Dispatcher::dispatch(Paid::class, new Paid($order));

            return;
        }

        if ($molliePayment->isExpired()) {
            Dispatcher::dispatch(
                PaymentExpired::class,
                new PaymentExpired($order)
            );

            return;
        }

        if ($molliePayment->isCanceled()) {
            Dispatcher::dispatch(
                PaymentCanceled::class,
                new PaymentCanceled($order)
            );

            return;
        }

        // Failed, or a status this port does not know — either way the
        // attempt did not succeed.
        Dispatcher::dispatch(PaymentFailed::class, new PaymentFailed($order));
    }

    /**
     * The configured `mollie.redirect_url`, or '' when unset.
     *
     * @return string
     */
    private function configuredRedirectUrl(): string
    {
        $configured = $this->config->get('mollie.redirect_url');

        return is_string($configured) ? $configured : '';
    }
}
