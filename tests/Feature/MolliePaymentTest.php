<?php

declare(strict_types=1);

/*
 * pay(), against a mock Mollie client (no network anywhere): the payment is
 * created from the frozen order — cents through Money::toDecimal(), the
 * reference as description, the configured URLs — the payment id lands on
 * the order, and the visitor is sent to Mollie's checkout through the
 * harness redirector.
 */

use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Fake\SequenceMockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

it('creates the Mollie payment from the frozen order', function (): void {
    $client = new MockMollieClient([
        CreatePaymentRequest::class => MockResponse::created(
            molliePaymentBody('tr_first', 'open')
        ),
    ]);

    [$gateway, $redirector] = makeGateway($client, bootEcommerceListeners());

    $order = orderAwaitingPayment();
    $gateway->pay($order);

    $client->assertSent(function (PendingRequest $request): bool {
        $payload = $request->payload()?->all();

        if (!is_array($payload)) {
            return false;
        }

        $amount = $payload['amount'] ?? null;

        return is_array($amount) &&
            ($amount['value'] ?? null) === '12.50' &&
            ($amount['currency'] ?? null) === 'EUR' &&
            ($payload['description'] ?? null) === '7-K4M7QX9RTB' &&
            ($payload['redirectUrl'] ?? null) ===
                'https://shop.example/checkout/return/?order=7' &&
            ($payload['webhookUrl'] ?? null) ===
                'https://shop.example/mollie-webhook/';
    });

    expect($order->payment_id)->toBe('tr_first');
    expect($redirector->sentTo)->toBe([
        'https://pay.mollie.example/checkout/tr_first',
    ]);

    // The gateway reported nothing — an open payment is not an outcome.
    expect($order->payment_status)->toBe('pending');
});

it('pays a zero total on the spot, like NullPayment', function (): void {
    // No expected responses: any API call would fail the test loudly.
    $client = new MockMollieClient([]);

    [$gateway, $redirector] = makeGateway($client, bootEcommerceListeners());

    $order = orderAwaitingPayment();
    $order->total = 0;

    $gateway->pay($order);

    expect($order->payment_status)->toBe('paid');
    expect($order->payment_id)->toBeNull();
    expect($redirector->sentTo)->toBe([
        'https://shop.example/checkout/return/?order=7',
    ]);
});

it(
    'reports a failed attempt when Mollie refuses the payment',
    function (): void {
        $client = new MockMollieClient([
            CreatePaymentRequest::class => MockResponse::unprocessableEntity(
                'The amount is higher than the maximum'
            ),
        ]);

        [$gateway, $redirector] = makeGateway(
            $client,
            bootEcommerceListeners()
        );

        $order = orderAwaitingPayment();
        $gateway->pay($order);

        // Failed keeps the order re-placeable; the visitor stays in the shop
        // with the basket still standing.
        expect($order->payment_status)->toBe('failed');
        expect($order->payment_id)->toBeNull();
        expect($redirector->sentTo)->toBe([]);
    }
);

it('gives a re-placed order a fresh payment id', function (): void {
    // Re-placement calls pay() again on the same row. The old payment is
    // dead at Mollie, so its id is overwritten — a webhook for the dead
    // attempt then finds no order, which is the right answer for it.
    $client = new MockMollieClient([
        CreatePaymentRequest::class => new SequenceMockResponse(
            MockResponse::created(molliePaymentBody('tr_first', 'open')),
            MockResponse::created(molliePaymentBody('tr_second', 'open'))
        ),
    ]);

    [$gateway, $redirector] = makeGateway($client, bootEcommerceListeners());

    $order = orderAwaitingPayment();

    $gateway->pay($order);
    $first = $order->payment_id;

    $gateway->pay($order);

    expect($first)->toBe('tr_first');
    expect($order->payment_id)->toBe('tr_second');
    expect($redirector->sentTo)->toBe([
        'https://pay.mollie.example/checkout/tr_first',
        'https://pay.mollie.example/checkout/tr_second',
    ]);
});
