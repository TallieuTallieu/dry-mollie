<?php

declare(strict_types=1);

/*
 * The webhook half: statusOf() interrogates Mollie's API (never the webhook
 * body) and maps its vocabulary onto PaymentStatus; dry-ecommerce's
 * PaymentWebhook dispatches and its listeners write the column. The replay
 * and late-arrival tests run through the real listeners, because the guard
 * they write through — PaymentStatus::canTransitionTo() — is the whole
 * idempotency story.
 */

use Mollie\Api\Exceptions\ServiceUnavailableException;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Fake\SequenceMockResponse;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Tests\Support\InMemoryPaymentWebhook;
use Tnt\Ecommerce\Payment\PaymentStatus;
use Tnt\Ecommerce\UnknownPayment;

it('maps every Mollie status onto the harness vocabulary', function (
    array $body,
    PaymentStatus $expected
): void {
    $client = new MockMollieClient([
        GetPaymentRequest::class => MockResponse::ok($body),
    ]);

    [$gateway] = makeGateway($client, bootEcommerceListeners());

    expect($gateway->statusOf('tr_first'))->toBe($expected);
})->with([
    // Not decided yet — pending is the answer that dispatches no event.
    'open' => [molliePaymentBody('tr_first', 'open'), PaymentStatus::Pending],
    'pending' => [
        molliePaymentBody('tr_first', 'pending'),
        PaymentStatus::Pending,
    ],
    // Authorized is only a reservation: the capture can still fail or be
    // voided, so nothing is reported until Mollie says paid.
    'authorized' => [
        molliePaymentBody('tr_first', 'authorized'),
        PaymentStatus::Pending,
    ],
    'paid' => [paidMolliePaymentBody('tr_first'), PaymentStatus::Paid],
    // Refunded is terminal in dry-ecommerce, so only a full return earns
    // it: a partial refund leaves the order paid, and re-placeable logic
    // untouched.
    'paid, fully refunded' => [
        paidMolliePaymentBody('tr_first', refunded: '12.50'),
        PaymentStatus::Refunded,
    ],
    'paid, partially refunded' => [
        paidMolliePaymentBody('tr_first', refunded: '1.00'),
        PaymentStatus::Paid,
    ],
    'paid, fully charged back' => [
        paidMolliePaymentBody('tr_first', chargedBack: '12.50'),
        PaymentStatus::Refunded,
    ],
    'paid, partially charged back' => [
        paidMolliePaymentBody('tr_first', chargedBack: '1.00'),
        PaymentStatus::Paid,
    ],
    // Refunds and chargebacks are both money going back; together they
    // add up to the whole payment.
    'paid, refunded and charged back to the full amount' => [
        paidMolliePaymentBody(
            'tr_first',
            refunded: '10.00',
            chargedBack: '2.50'
        ),
        PaymentStatus::Refunded,
    ],
    // Mollie allows refunding over the payment, to reimburse a return
    // shipment. Still everything back.
    'paid, refunded over the payment' => [
        paidMolliePaymentBody('tr_first', refunded: '15.00'),
        PaymentStatus::Refunded,
    ],
    // The refund link is there but nothing has moved yet.
    'paid, refund link with nothing refunded' => [
        paidMolliePaymentBody('tr_first', refunded: '0.00'),
        PaymentStatus::Paid,
    ],
    'failed' => [
        molliePaymentBody('tr_first', 'failed'),
        PaymentStatus::Failed,
    ],
    'canceled' => [
        molliePaymentBody('tr_first', 'canceled'),
        PaymentStatus::Canceled,
    ],
    'expired' => [
        molliePaymentBody('tr_first', 'expired'),
        PaymentStatus::Expired,
    ],
]);

it(
    'marks the order through the package webhook and listeners',
    function (): void {
        $dispatcher = bootEcommerceListeners();

        $client = new MockMollieClient([
            GetPaymentRequest::class => MockResponse::ok(
                paidMolliePaymentBody('tr_first')
            ),
        ]);

        [$gateway] = makeGateway($client, $dispatcher);

        $order = orderAwaitingPayment('tr_first');

        $webhook = new InMemoryPaymentWebhook($gateway, $dispatcher);
        $webhook->orders['tr_first'] = $order;

        $webhook->handle('tr_first');

        expect($order->payment_status)->toBe('paid');
    }
);

it('takes a replayed paid webhook as a no-op', function (): void {
    $dispatcher = bootEcommerceListeners();

    $client = new MockMollieClient([
        GetPaymentRequest::class => new SequenceMockResponse(
            MockResponse::ok(paidMolliePaymentBody('tr_first')),
            MockResponse::ok(paidMolliePaymentBody('tr_first'))
        ),
    ]);

    [$gateway] = makeGateway($client, $dispatcher);

    $order = orderAwaitingPayment('tr_first');

    $webhook = new InMemoryPaymentWebhook($gateway, $dispatcher);
    $webhook->orders['tr_first'] = $order;

    $webhook->handle('tr_first');
    $savesAfterFirst = $order->saveCount;

    $webhook->handle('tr_first');

    expect($order->payment_status)->toBe('paid');

    // The guard blocks paid -> paid, so the replay writes nothing at all.
    expect($order->saveCount)->toBe($savesAfterFirst);
});

it('keeps a paid order paid through a late expired webhook', function (): void {
    // The gateway maps honestly both times; the listener's guard is what
    // refuses to unsay that the money arrived.
    $dispatcher = bootEcommerceListeners();

    $client = new MockMollieClient([
        GetPaymentRequest::class => new SequenceMockResponse(
            MockResponse::ok(paidMolliePaymentBody('tr_first')),
            MockResponse::ok(molliePaymentBody('tr_first', 'expired'))
        ),
    ]);

    [$gateway] = makeGateway($client, $dispatcher);

    $order = orderAwaitingPayment('tr_first');

    $webhook = new InMemoryPaymentWebhook($gateway, $dispatcher);
    $webhook->orders['tr_first'] = $order;

    $webhook->handle('tr_first');
    $webhook->handle('tr_first');

    expect($order->payment_status)->toBe('paid');
});

it('still refunds after the money arrived', function (): void {
    $dispatcher = bootEcommerceListeners();

    $client = new MockMollieClient([
        GetPaymentRequest::class => new SequenceMockResponse(
            MockResponse::ok(paidMolliePaymentBody('tr_first')),
            MockResponse::ok(
                paidMolliePaymentBody('tr_first', refunded: '12.50')
            )
        ),
    ]);

    [$gateway] = makeGateway($client, $dispatcher);

    $order = orderAwaitingPayment('tr_first');

    $webhook = new InMemoryPaymentWebhook($gateway, $dispatcher);
    $webhook->orders['tr_first'] = $order;

    $webhook->handle('tr_first');
    $webhook->handle('tr_first');

    expect($order->payment_status)->toBe('refunded');
});

it('lets a Mollie failure out of statusOf', function (): void {
    // The opposite of pay(): the webhook wants the failure, so the host can
    // answer non-2xx and Mollie retries for hours. Swallowing it here would
    // answer 200 to a question that was never asked.
    $client = new MockMollieClient([
        GetPaymentRequest::class => MockResponse::error(
            503,
            'Service Unavailable',
            'The Mollie API is temporarily unavailable'
        ),
    ]);

    [$gateway] = makeGateway($client, bootEcommerceListeners());

    $gateway->statusOf('tr_first');
})->throws(ServiceUnavailableException::class);

it('refuses a payment id no order carries', function (): void {
    $dispatcher = bootEcommerceListeners();

    // No expected responses: the handler must refuse BEFORE asking Mollie —
    // any API call here would fail the test loudly.
    $client = new MockMollieClient([]);

    [$gateway] = makeGateway($client, $dispatcher);

    $webhook = new InMemoryPaymentWebhook($gateway, $dispatcher);

    $webhook->handle('tr_never_issued');
})->throws(UnknownPayment::class, 'tr_never_issued');
