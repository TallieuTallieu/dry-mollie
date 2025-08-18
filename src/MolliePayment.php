<?php

namespace Tnt\Mollie;

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
use dry\db\FetchException;
use dry\http\Response;
use dry\route\NotFound;

/**
 * Mollie payment implementation for processing payments via the Mollie API.
 *
 * This class handles payment creation, redirection to Mollie's checkout,
 * and webhook processing for payment status updates. It integrates with
 * the ecommerce system to dispatch appropriate events based on payment states.
 */
class MolliePayment implements PaymentInterface
{
    /**
     * Configuration repository for accessing Mollie settings.
     *
     * @var RepositoryInterface
     */
    private $config;

    /**
     * Mollie API client for communicating with the Mollie API.
     *
     * @var MollieApiClient
     */
    private $mollie;

    /**
     * Event dispatcher for triggering payment-related events.
     *
     * @var DispatcherInterface
     */
    private $dispatcher;

    /**
     * Initialize the Mollie payment service with required dependencies.
     *
     * @param RepositoryInterface $config Configuration repository for Mollie settings
     * @param MollieApiClient $mollie Configured Mollie API client
     * @param DispatcherInterface $dispatcher Event dispatcher for payment events
     */
    public function __construct(
        RepositoryInterface $config,
        MollieApiClient $mollie,
        DispatcherInterface $dispatcher
    ) {
        $this->config = $config;
        $this->mollie = $mollie;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Initiate payment for an order using Mollie.
     *
     * Creates a Mollie payment for the given order and redirects the user
     * to Mollie's checkout page. If the order total is zero, the payment
     * is marked as complete immediately. Handles payment failures by
     * dispatching appropriate events.
     *
     * @param OrderInterface $order The order to process payment for
     * @return void
     * @throws ApiException When Mollie API communication fails
     */
    public function pay(OrderInterface $order): void
    {
        $orderTotal = $order->getTotal();

        if ($orderTotal > 0) {
            // Format total price as a string (needed for Mollie)
            $formattedAmount = number_format($order->getTotal(), 2, '.', '');

            try {
                // Create the Mollie payment
                $molliePayment = $this->mollie->payments->create([
                    'amount' => [
                        'currency' => 'EUR',
                        'value' => $formattedAmount,
                    ],
                    'description' => $order->order_id,
                    'redirectUrl' =>
                        $this->config->get('mollie.redirect_url') .
                        '?cancel=false&order=' .
                        $order->id,
                    'cancelUrl' =>
                        $this->config->get('mollie.redirect_url') .
                        '?cancel=true&order=' .
                        $order->id,
                    'webhookUrl' => \dry\abs_url('mollie-webhook/'),
                ]);

                // Store the Mollie payment id in the order
                $order->payment_id = $molliePayment->id;
                $order->save();

                // Redirect to Mollie
                Response::redirect($molliePayment->getCheckoutUrl());
            } catch (ApiException $e) {
                // Payment failed
                $this->dispatcher->dispatch(
                    PaymentFailed::class,
                    new PaymentFailed($order)
                );
            }
        } else {
            // Payment complete
            $this->dispatcher->dispatch(Paid::class, new Paid($order));

            // Redirect to the page!
            Response::redirect($this->config->get('mollie.redirect_url'));
        }
    }

    /**
     * Process a Mollie payment status update from webhook.
     *
     * Retrieves the payment from Mollie API, finds the associated order,
     * and dispatches appropriate events based on the payment status.
     * This method is called by webhook notifications to update payment states.
     *
     * @param MollieApiClient $mollieApiClient The Mollie API client
     * @param string $paymentId The Mollie payment ID to process
     * @return void
     * @throws NotFound When no order is found for the payment ID
     * @throws FetchException When database query fails
     * @throws ApiException When Mollie API communication fails
     */
    public static function process(MollieApiClient $mollieApiClient, string $paymentId): void
    {
        $molliePayment = $mollieApiClient->payments->get($paymentId);
        $paymentId = $molliePayment->id;

        try {
            $order = Order::load_by('payment_id', $paymentId);
        } catch (FetchException $e) {
            throw new NotFound();
        }

        if ($molliePayment->isPaid()) {
            if ($molliePayment->hasRefunds()) {
                // Payment refunded
                Dispatcher::dispatch(
                    PaymentRefunded::class,
                    new PaymentRefunded($order, $molliePayment->refunds())
                );
                return;
            }

            // Payment complete
            Dispatcher::dispatch(Paid::class, new Paid($order));
        } elseif ($molliePayment->isExpired()) {
            // Payment is expired
            Dispatcher::dispatch(
                PaymentExpired::class,
                new PaymentExpired($order)
            );
        } elseif ($molliePayment->isCanceled()) {
            // Payment was canceled by user
            Dispatcher::dispatch(
                PaymentCanceled::class,
                new PaymentCanceled($order)
            );
        } elseif ($molliePayment->isFailed()) {
            // Payment is failed
            Dispatcher::dispatch(
                PaymentFailed::class,
                new PaymentFailed($order)
            );
        } else {
            // Generic payment failed this should never happen
            Dispatcher::dispatch(
                PaymentFailed::class,
                new PaymentFailed($order)
            );
        }
    }
}
