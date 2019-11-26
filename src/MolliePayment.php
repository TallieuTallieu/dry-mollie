<?php

namespace Tnt\Mollie;

use dry\http\Response;
use Mollie\Api\MollieApiClient;
use Oak\Contracts\Config\RepositoryInterface;
use Oak\Contracts\Dispatcher\DispatcherInterface;
use Tnt\Ecommerce\Contracts\OrderInterface;
use Tnt\Ecommerce\Contracts\PaymentInterface;
use Tnt\Ecommerce\Events\Order\Paid;

class MolliePayment implements PaymentInterface
{
    /**
     * @var RepositoryInterface $config
     */
    private $config;

    /**
     * @var MollieApiClient $mollie
     */
    private $mollie;

    /**
     * @var DispatcherInterface $dispatcher
     */
    private $dispatcher;

    /**
     * MolliePayment constructor.
     * @param RepositoryInterface $config
     * @param MollieApiClient $mollie
     * @param DispatcherInterface $dispatcher
     */
    public function __construct(RepositoryInterface $config, MollieApiClient $mollie, DispatcherInterface $dispatcher)
    {
        $this->config = $config;
        $this->mollie = $mollie;
        $this->dispatcher = $dispatcher;
    }

    public function pay(OrderInterface $order)
    {
        $total = $order->getTotal();

        if ($total > 0) {

            // Format total price as a string (needed for Mollie)
            $total = number_format($order->getTotal(), 2, '.', '');

            // Create the Mollie payment
            $payment = $this->mollie->payments->create([
                'amount' => [
                    'currency' => 'EUR',
                    'value' => $total,
                ],
                'description' => 'My first API payment',
                'redirectUrl' => $this->config->get('mollie.redirect_url'),
                'webhookUrl'  => \dry\abs_url('mollie-webhook/'),
            ]);

            // Store the Mollie payment id in the order
            $order->payment_id = $payment->id;
            $order->save();

            // Redirect to Mollie
            Response::redirect($payment->getCheckoutUrl());

        } else {

            // Payment complete
            $this->dispatcher->dispatch(Paid::class, new Paid($order));

            // Redirect to the page!
            Response::redirect($this->config->get('mollie.redirect_url'));
        }
    }
}