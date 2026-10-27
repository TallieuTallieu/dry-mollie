<?php

declare(strict_types=1);

/*
 * The extra logging while dry's debug mode is on: what pay() sent and got,
 * why it refused, and what reportOf() read. Off, nothing is logged; on, a
 * log that cannot be written never costs the payment.
 */

use dry\Debug;
use dry\log\Log;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Tests\Support\RecordingLogger;
use Tnt\Ecommerce\Payment\PaymentRedirect;
use Tnt\Ecommerce\Payment\PaymentRefused;

/**
 * Set one of dry's private statics; neither Debug nor Log can be reset
 * through its API.
 *
 * @param class-string $class
 * @param string $property
 * @param mixed $value
 * @return void
 */
function setDryStatic(string $class, string $property, mixed $value): void
{
    (new ReflectionProperty($class, $property))->setValue(null, $value);
}

/**
 * Debug mode on and a recording logger as the default one.
 *
 * @return RecordingLogger
 */
function debugModeWithLogger(): RecordingLogger
{
    setDryStatic(Debug::class, 'enabled', true);

    $logger = new RecordingLogger();
    Log::register_logger('test', $logger);
    Log::set_default_logger('test');

    return $logger;
}

/**
 * A mock client that creates the payment pay() asks for.
 *
 * @return MockMollieClient
 */
function creatingClient(): MockMollieClient
{
    return new MockMollieClient([
        CreatePaymentRequest::class => MockResponse::created(
            molliePaymentBody('tr_first', 'open')
        ),
    ]);
}

afterEach(function (): void {
    setDryStatic(Debug::class, 'enabled', false);
    setDryStatic(Log::class, 'loggers', []);
    setDryStatic(Log::class, 'default_logger', null);
});

it('logs nothing while debug mode is off', function (): void {
    $logger = new RecordingLogger();
    Log::register_logger('test', $logger);
    Log::set_default_logger('test');

    makeGateway(creatingClient())->pay(orderAwaitingPayment());

    expect($logger->records())->toBe([]);
});

it('logs the payload sent and the payment created', function (): void {
    $logger = debugModeWithLogger();

    $order = orderAwaitingPayment();
    $order->email = 'jan@example.be';

    makeGateway(creatingClient())->pay($order);

    $creating = $logger->record('Mollie: Creating payment');
    $payload = $creating?->context['payload'] ?? null;

    expect($creating?->context['order_id'] ?? null)->toBe(7);
    expect(is_array($payload) ? $payload['redirectUrl'] ?? null : null)->toBe(
        'https://shop.example/checkout/return/?order=7'
    );

    expect($logger->record('Mollie: Payment created')?->context)->toBe([
        'order_id' => 7,
        'payment_id' => 'tr_first',
        'checkout_url' => 'https://pay.mollie.example/checkout/tr_first',
    ]);
});

it('logs the billing address by its fields, not its values', function (): void {
    $logger = debugModeWithLogger();

    $order = orderAwaitingPayment();
    $order->email = 'jan@example.be';

    makeGateway(creatingClient())->pay($order);

    $payload =
        $logger->record('Mollie: Creating payment')?->context['payload'] ??
        null;

    expect(
        is_array($payload) ? $payload['billingAddress'] ?? null : null
    )->toBe(['email']);
    expect(json_encode($logger->records()))->not->toContain('jan@example.be');
});

it('logs why Mollie refused the payment', function (): void {
    $logger = debugModeWithLogger();

    $client = new MockMollieClient([
        CreatePaymentRequest::class => MockResponse::unprocessableEntity(
            'The amount is higher than the maximum'
        ),
    ]);

    expect(makeGateway($client)->pay(orderAwaitingPayment()))->toEqual(
        new PaymentRefused()
    );

    $exception =
        $logger->record('Mollie: Payment refused by Mollie')?->context[
            'exception'
        ] ?? null;

    expect($exception)->toBeInstanceOf(Throwable::class);
    expect(
        $exception instanceof Throwable ? $exception->getMessage() : ''
    )->toContain('The amount is higher than the maximum');
});

it('logs what reportOf read', function (): void {
    $logger = debugModeWithLogger();

    $client = new MockMollieClient([
        GetPaymentRequest::class => MockResponse::ok(
            paidMolliePaymentBody('tr_first')
        ),
    ]);

    makeGateway($client)->reportOf('tr_first');

    expect($logger->record('Mollie: Payment reported')?->context)->toBe([
        'payment_id' => 'tr_first',
        'mollie_status' => 'paid',
        'status' => 'paid',
        'method' => 'ideal',
        'card_label' => null,
        'movements' => [
            ['kind' => 'captured', 'reference' => 'tr_first', 'amount' => 1250],
        ],
    ]);
});

it('pays on when the log cannot be written', function (): void {
    // Debug mode on but no logger: dry's Log throws, the gateway does not.
    setDryStatic(Debug::class, 'enabled', true);

    expect(makeGateway(creatingClient())->pay(orderAwaitingPayment()))->toEqual(
        new PaymentRedirect(
            'tr_first',
            'https://pay.mollie.example/checkout/tr_first'
        )
    );
});
