<?php

declare(strict_types=1);

/*
 * pay(), against a mock Mollie client (no network anywhere): the payment is
 * created from the frozen order — cents through Money::toDecimal(), the
 * reference as description, the configured URLs — and pay() answers what
 * happened. It writes nothing on the order and redirects nowhere; the
 * package records the outcome and sends the visitor.
 */

use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\LinearRetryStrategy;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Oak\Config\Repository;
use Tests\Support\NetworkFailure;
use Tnt\Ecommerce\Payment\EntryKind;
use Tnt\Ecommerce\Payment\PaymentRedirect;
use Tnt\Ecommerce\Payment\PaymentRefused;
use Tnt\Ecommerce\Payment\PaymentSettled;
use Tnt\Ecommerce\Payment\PaymentStatus;
use Tnt\Mollie\MollieClientFactory;

it('files its entries under mollie', function (): void {
    expect(makeGateway(new MockMollieClient([]))->provider())->toBe('mollie');
});

it('creates the Mollie payment from the frozen order', function (): void {
    $client = new MockMollieClient([
        CreatePaymentRequest::class => MockResponse::created(
            molliePaymentBody('tr_first', 'open')
        ),
    ]);

    $order = orderAwaitingPayment();
    $outcome = makeGateway($client)->pay($order);

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

    expect($outcome)->toEqual(
        new PaymentRedirect(
            'tr_first',
            'https://pay.mollie.example/checkout/tr_first'
        )
    );

    // The package writes the pointer, not the gateway.
    expect($order->payment_id)->toBeNull();
});

it('settles a zero total on the spot, like NullPayment', function (): void {
    // No expected responses: any API call would fail the test loudly.
    $gateway = makeGateway(new MockMollieClient([]));

    $order = orderAwaitingPayment();
    $order->total = 0;

    $outcome = $gateway->pay($order);

    expect($outcome)->toBeInstanceOf(PaymentSettled::class);

    /** @var PaymentSettled $outcome */
    $report = $outcome->report;

    expect($report->paymentId)->toStartWith('free_');
    expect($report->status)->toBe(PaymentStatus::Paid);
    expect($report->movements)->toHaveCount(1);
    expect($report->movements[0]->kind)->toBe(EntryKind::Captured);
    expect($report->movements[0]->reference)->toBe($report->paymentId);
    expect($report->movements[0]->amount)->toBe(0);
});

it('mints a fresh free payment per placement', function (): void {
    // A re-placement is an attempt of its own, with a capture of its own.
    $gateway = makeGateway(new MockMollieClient([]));

    $order = orderAwaitingPayment();
    $order->total = 0;

    /** @var PaymentSettled $first */
    $first = $gateway->pay($order);
    /** @var PaymentSettled $second */
    $second = $gateway->pay($order);

    expect($first->report->paymentId)->not->toBe($second->report->paymentId);
});

it('reads a free order as paid once the ledger has it', function (): void {
    $ledger = makeLedger();

    $order = orderAwaitingPayment();
    $order->total = 0;

    $ledger->start(
        $order,
        'mollie',
        makeGateway(new MockMollieClient([]))->pay($order)
    );

    expect($order->getPaymentStatus())->toBe(PaymentStatus::Paid);
});

it('answers refused however the payment fails', function (
    MockResponse|Closure $response
): void {
    $client = new MockMollieClient(
        [CreatePaymentRequest::class => $response],
        retainRequests: true
    );

    // Skip the backoff a dropped connection would otherwise sit out.
    $client->setRetryStrategy(new LinearRetryStrategy(maxRetries: 0));

    $order = orderAwaitingPayment();

    expect(makeGateway($client)->pay($order))->toEqual(new PaymentRefused());
    expect($order->payment_id)->toBeNull();
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

it('answers refused when the API key is not usable', function (): void {
    // The key is refused while the client is built, before any request.
    $factory = new MollieClientFactory(
        new Repository(['mollie' => ['api_key' => 'not-a-mollie-key']])
    );

    expect(makeGateway($factory)->pay(orderAwaitingPayment()))->toEqual(
        new PaymentRefused()
    );
});

it(
    'answers refused, with the id, when the payment has no checkout',
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

        expect(makeGateway($client)->pay(orderAwaitingPayment()))->toEqual(
            new PaymentRefused('tr_first')
        );
    }
);

it('leaves a refused order failed once the ledger has it', function (): void {
    $client = new MockMollieClient([
        CreatePaymentRequest::class => MockResponse::error(
            503,
            'Service Unavailable',
            'The Mollie API is temporarily unavailable'
        ),
    ]);

    $ledger = makeLedger();
    $order = orderAwaitingPayment();

    $ledger->start($order, 'mollie', makeGateway($client)->pay($order));

    expect($order->getPaymentStatus())->toBe(PaymentStatus::Failed);
});
