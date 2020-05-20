<?php

namespace Tnt\Mollie\Controller;

use dry\db\FetchException;
use dry\Debug;
use dry\http\Request;
use dry\route\NotFound;
use Mollie\Api\MollieApiClient;
use Oak\Dispatcher\Facade\Dispatcher;
use Tnt\Ecommerce\Events\Order\Paid;
use Tnt\Ecommerce\Events\Order\PaymentCanceled;
use Tnt\Ecommerce\Events\Order\PaymentExpired;
use Tnt\Ecommerce\Events\Order\PaymentFailed;
use Tnt\Ecommerce\Events\Order\PaymentRefunded;
use Tnt\Ecommerce\Model\Order;

class WebhookController
{
    public static function process(Request $request, MollieApiClient $mollieApiClient)
    {
        $payment = $mollieApiClient->payments->get($request->post->string('id'));
        $orderPaymentId = $payment->id;

        try {
            $order = Order::load_by('payment_id', $orderPaymentId);
        } catch (FetchException $e) {
            throw new NotFound();
        }

        if ($payment->isPaid()) {

            if ($payment->hasRefunds()) {
                // Payment refunded
                Dispatcher::dispatch(PaymentRefunded::class, new PaymentRefunded($order));
                return;
            }

            // Payment complete
            Dispatcher::dispatch(Paid::class, new Paid($order));

        } elseif (! $payment->isOpen()) {

            if ($payment->isExpired()) {

                // Payment is expired
                Dispatcher::dispatch(PaymentExpired::class, new PaymentExpired($order));

            } elseif ($payment->isCanceled()) {

                // Payment was canceled by user
                Dispatcher::dispatch(PaymentCanceled::class, new PaymentCanceled($order));
            }
        } else {

            // Generic payment failed
            Dispatcher::dispatch(PaymentFailed::class, new PaymentFailed($order));
        }
    }
}
