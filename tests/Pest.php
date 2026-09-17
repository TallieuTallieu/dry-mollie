<?php

use Mollie\Api\MollieApiClient;
use Oak\Config\Repository;
use Oak\Contracts\Dispatcher\DispatcherInterface;
use Oak\Dispatcher\Dispatcher;
use Tests\Support\FakeRedirector;
use Tests\Support\FixedMollieClientFactory;
use Tests\Support\InMemoryOrder;
use Tests\Support\NoopCartRelease;
use Tests\Support\WebContainer;
use Tnt\Ecommerce\Cart\CartRelease;
use Tnt\Ecommerce\EcommerceServiceProvider;
use Tnt\Ecommerce\Payment\PaymentStatus;
use Tnt\Mollie\Contracts\MollieClientFactoryInterface;
use Tnt\Mollie\MolliePayment;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

uses(Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * dry-ecommerce booted for real, and the dispatcher its listeners are on.
 *
 * The idempotency this package leans on lives in those listeners — the
 * transition guard they write `payment_status` through — so the webhook
 * tests dispatch through them rather than asserting on dispatch calls.
 * Same arrangement as dry-ecommerce's own bootEcommerce(): the Paid
 * listener resolves CartRelease from the container, so a no-op release is
 * bound in its place.
 *
 * @return DispatcherInterface
 */
function bootEcommerceListeners(): DispatcherInterface
{
    $app = new WebContainer();

    $app->singleton(DispatcherInterface::class, Dispatcher::class);
    $app->singleton(CartRelease::class, NoopCartRelease::class);

    Oak\Facade::setContainer($app);

    (new EcommerceServiceProvider())->boot($app);

    /** @var DispatcherInterface $dispatcher */
    $dispatcher = $app->get(DispatcherInterface::class);

    return $dispatcher;
}

/**
 * The gateway under test, wired to a (mock) Mollie client and a redirector
 * that records instead of exiting. Config keys per docs/gateway.md.
 *
 * A factory may be passed in place of a client, for the tests that need
 * the client's own construction to fail.
 *
 * @param MollieApiClient|MollieClientFactoryInterface $client
 * @param DispatcherInterface $dispatcher
 * @param array<string, mixed> $configOverrides
 * @return array{MolliePayment, FakeRedirector}
 */
function makeGateway(
    MollieApiClient|MollieClientFactoryInterface $client,
    DispatcherInterface $dispatcher,
    array $configOverrides = []
): array {
    $redirector = new FakeRedirector();

    $config = new Repository([
        'mollie' => array_merge(
            [
                'api_key' => 'test_dummy',
                'redirect_url' => 'https://shop.example/checkout/return/',
                'webhook_url' => 'https://shop.example/mollie-webhook/',
            ],
            $configOverrides
        ),
    ]);

    $factory =
        $client instanceof MollieApiClient
            ? new FixedMollieClientFactory($client)
            : $client;

    $gateway = new MolliePayment($config, $factory, $dispatcher, $redirector);

    return [$gateway, $redirector];
}

/**
 * A placed order awaiting its payment: pending, with a payment id when the
 * webhook needs to find it.
 *
 * @param string|null $paymentId
 * @return InMemoryOrder
 */
function orderAwaitingPayment(?string $paymentId = null): InMemoryOrder
{
    $order = new InMemoryOrder();
    $order->id = 7;
    $order->order_id = '7-K4M7QX9RTB';
    $order->total = 1250;
    $order->payment_status = PaymentStatus::Pending->value;

    if ($paymentId !== null) {
        $order->payment_id = $paymentId;
    }

    return $order;
}

/**
 * A Mollie payment resource body, as the mock client will serve it.
 *
 * @param string $id
 * @param string $status
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function molliePaymentBody(
    string $id,
    string $status,
    array $overrides = []
): array {
    return array_merge(
        [
            'resource' => 'payment',
            'id' => $id,
            'mode' => 'test',
            'createdAt' => '2026-09-01T10:00:00+00:00',
            'amount' => ['value' => '12.50', 'currency' => 'EUR'],
            'description' => '7-K4M7QX9RTB',
            'method' => 'ideal',
            'status' => $status,
            'profileId' => 'pfl_test',
            'sequenceType' => 'oneoff',
            'redirectUrl' => 'https://shop.example/checkout/return/?order=7',
            'webhookUrl' => 'https://shop.example/mollie-webhook/',
            '_links' => [
                'self' => [
                    'href' => 'https://api.mollie.com/v2/payments/' . $id,
                    'type' => 'application/hal+json',
                ],
                'checkout' => [
                    'href' => 'https://pay.mollie.example/checkout/' . $id,
                    'type' => 'text/html',
                ],
            ],
        ],
        $overrides
    );
}

/**
 * The same body once the money arrived — and optionally went back.
 *
 * Refunds and chargebacks are given as amounts, not flags, because that is
 * the only thing that tells a partial return from a full one. Each one adds
 * both the link Mollie hangs off the payment and the running total it keeps
 * beside it.
 *
 * @param string $id
 * @param string|null $refunded What has been refunded, e.g. '12.50'.
 * @param string|null $chargedBack What has been charged back, e.g. '12.50'.
 * @return array<string, mixed>
 */
function paidMolliePaymentBody(
    string $id,
    ?string $refunded = null,
    ?string $chargedBack = null
): array {
    $links = [
        'self' => [
            'href' => 'https://api.mollie.com/v2/payments/' . $id,
            'type' => 'application/hal+json',
        ],
    ];

    $overrides = [
        'paidAt' => '2026-09-01T10:05:00+00:00',
    ];

    if ($refunded !== null) {
        $links['refunds'] = [
            'href' => 'https://api.mollie.com/v2/payments/' . $id . '/refunds',
            'type' => 'application/hal+json',
        ];

        $overrides['amountRefunded'] = [
            'value' => $refunded,
            'currency' => 'EUR',
        ];
    }

    if ($chargedBack !== null) {
        $links['chargebacks'] = [
            'href' =>
                'https://api.mollie.com/v2/payments/' . $id . '/chargebacks',
            'type' => 'application/hal+json',
        ];

        $overrides['amountChargedBack'] = [
            'value' => $chargedBack,
            'currency' => 'EUR',
        ];
    }

    $overrides['_links'] = $links;

    return molliePaymentBody($id, 'paid', $overrides);
}
