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
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Oak\Config\Repository;
use Tests\Support\NetworkFailure;
use Tnt\Mollie\MollieClientFactory;

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

it('reports a failed attempt however the payment fails', function (
    MockResponse|Closure $response
): void {
    $client = new MockMollieClient(
        [CreatePaymentRequest::class => $response],
        retainRequests: true
    );

    // Skip the backoff a dropped connection would otherwise sit out.
    $client->setRetryStrategy(new LinearRetryStrategy(maxRetries: 0));

    [$gateway, $redirector] = makeGateway($client, bootEcommerceListeners());

    $order = orderAwaitingPayment();
    $gateway->pay($order);

    expect($order->payment_status)->toBe('failed');
    expect($order->payment_id)->toBeNull();
    expect($redirector->sentTo)->toBe([]);
})->with([
    // ValidationException, under ApiException.
    'Mollie refuses the payment' => fn() => MockResponse::unprocessableEntity(
        'The amount is higher than the maximum'
    ),
    // NotFoundException, under ApiException.
    'Mollie answers 404' => fn() => MockResponse::notFound(),
    // ServiceUnavailableException, under ServerException.
    'Mollie is down' => fn() => MockResponse::error(
        503,
        'Service Unavailable',
        'The Mollie API is temporarily unavailable'
    ),
    // RequestTimeoutException, under NetworkRequestException.
    'Mollie times out' => fn() => MockResponse::error(
        408,
        'Request Timeout',
        'The request took too long'
    ),
    // RetryableNetworkRequestException, under NetworkRequestException.
    'the connection never lands' => fn() => fn(
        PendingRequest $request
    ): MockResponse => throw new NetworkFailure('Connection refused'),
]);

it(
    'reports a failed attempt when the API key is not usable',
    function (): void {
        // The key is refused while the client is built, before any request.
        $factory = new MollieClientFactory(
            new Repository(['mollie' => ['api_key' => 'not-a-mollie-key']])
        );

        [$gateway, $redirector] = makeGateway(
            $factory,
            bootEcommerceListeners()
        );

        $order = orderAwaitingPayment();
        $gateway->pay($order);

        expect($order->payment_status)->toBe('failed');
        expect($order->payment_id)->toBeNull();
        expect($redirector->sentTo)->toBe([]);
    }
);

it(
    'reports a failed attempt when the payment has no checkout',
    function (): void {
        // Mollie created the payment but gave it no checkout link.
        $client = new MockMollieClient([
            CreatePaymentRequest::class => MockResponse::created(
                molliePaymentBody('tr_first', 'open', [
                    '_links' => [
                        'self' => [
                            'href' =>
                                'https://api.mollie.com/v2/payments/tr_first',
                            'type' => 'application/hal+json',
                        ],
                    ],
                ])
            ),
        ]);

        [$gateway, $redirector] = makeGateway(
            $client,
            bootEcommerceListeners()
        );

        $order = orderAwaitingPayment();
        $gateway->pay($order);

        expect($order->payment_status)->toBe('failed');
        expect($order->payment_id)->toBeNull();
        expect($redirector->sentTo)->toBe([]);
    }
);

it('drops the old payment id when the retry fails', function (): void {
    // A late webhook for the dead first payment must not speak for the order.
    $client = new MockMollieClient([
        CreatePaymentRequest::class => new SequenceMockResponse(
            MockResponse::created(molliePaymentBody('tr_first', 'open')),
            MockResponse::error(
                503,
                'Service Unavailable',
                'The Mollie API is temporarily unavailable'
            )
        ),
    ]);

    [$gateway, $redirector] = makeGateway($client, bootEcommerceListeners());

    $order = orderAwaitingPayment();

    $gateway->pay($order);
    expect($order->payment_id)->toBe('tr_first');

    $gateway->pay($order);

    expect($order->payment_id)->toBeNull();
    expect($order->payment_status)->toBe('failed');
    expect($redirector->sentTo)->toBe([
        'https://pay.mollie.example/checkout/tr_first',
    ]);
});

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
