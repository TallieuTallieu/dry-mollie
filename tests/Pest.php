<?php

use Mollie\Api\MollieApiClient;
use Oak\Config\Repository;
use Oak\Dispatcher\Dispatcher;
use Tests\Support\FixedMollieClientFactory;
use Tests\Support\InMemoryOrder;
use Tests\Support\InMemoryPaymentLedger;
use Tnt\Ecommerce\Payment\PaymentRedirect;
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
 * The gateway under test, wired to a (mock) Mollie client. Config keys per
 * docs/gateway.md.
 *
 * A factory may be passed in place of a client, for the tests that need
 * the client's own construction to fail.
 *
 * @param MollieApiClient|MollieClientFactoryInterface $client
 * @return MolliePayment
 */
function makeGateway(
    MollieApiClient|MollieClientFactoryInterface $client
): MolliePayment {
    $config = new Repository([
        'mollie' => [
            'api_key' => 'test_dummy',
            'redirect_url' => 'https://shop.example/checkout/return/',
            'webhook_url' => 'https://shop.example/mollie-webhook/',
        ],
    ]);

    $factory =
        $client instanceof MollieApiClient
            ? new FixedMollieClientFactory($client)
            : $client;

    return new MolliePayment($config, $factory);
}

/**
 * dry-ecommerce's real ledger, writing to memory. Its events go to a
 * dispatcher with no listeners: what the order reads is the point here.
 *
 * @return InMemoryPaymentLedger
 */
function makeLedger(): InMemoryPaymentLedger
{
    return new InMemoryPaymentLedger(new Dispatcher());
}

/**
 * A placed order awaiting its payment.
 *
 * @return InMemoryOrder
 */
function orderAwaitingPayment(): InMemoryOrder
{
    $order = new InMemoryOrder();
    $order->id = 7;
    $order->order_id = '7-K4M7QX9RTB';
    $order->total = 1250;
    $order->payment_status = PaymentStatus::Pending->value;

    return $order;
}

/**
 * An order whose Mollie payment was started, as `Cart::place()` records it —
 * what the webhook finds the order through.
 *
 * @param InMemoryPaymentLedger $ledger
 * @param string $paymentId
 * @return InMemoryOrder
 */
function orderPayingWith(
    InMemoryPaymentLedger $ledger,
    string $paymentId
): InMemoryOrder {
    $order = orderAwaitingPayment();

    $ledger->start(
        $order,
        'mollie',
        new PaymentRedirect(
            $paymentId,
            'https://pay.mollie.example/checkout/' . $paymentId
        )
    );

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
 * The same body once the money arrived — and optionally went back, with
 * the refunds and chargebacks embedded as `embed=refunds,chargebacks`
 * serves them.
 *
 * @param string $id
 * @param list<array<string, mixed>> $refunds From refund().
 * @param list<array<string, mixed>> $chargebacks From chargeback().
 * @return array<string, mixed>
 */
function paidMolliePaymentBody(
    string $id,
    array $refunds = [],
    array $chargebacks = []
): array {
    $overrides = [
        'paidAt' => '2026-09-01T10:05:00+00:00',
        '_links' => [
            'self' => [
                'href' => 'https://api.mollie.com/v2/payments/' . $id,
                'type' => 'application/hal+json',
            ],
        ],
    ];

    $embedded = array_filter([
        'refunds' => $refunds,
        'chargebacks' => $chargebacks,
    ]);

    if ($embedded !== []) {
        $overrides['_embedded'] = $embedded;
    }

    return molliePaymentBody($id, 'paid', $overrides);
}

/**
 * A Mollie refund resource body.
 *
 * @param string $id
 * @param string $status
 * @param string $amount
 * @return array<string, mixed>
 */
function refund(string $id, string $status, string $amount): array
{
    return [
        'resource' => 'refund',
        'id' => $id,
        'mode' => 'test',
        'amount' => ['value' => $amount, 'currency' => 'EUR'],
        'status' => $status,
        'createdAt' => '2026-09-02T10:00:00+00:00',
        'description' => 'Refund',
        'paymentId' => 'tr_first',
    ];
}

/**
 * A Mollie chargeback resource body.
 *
 * @param string $id
 * @param string $amount
 * @param bool $reversed
 * @return array<string, mixed>
 */
function chargeback(string $id, string $amount, bool $reversed = false): array
{
    return [
        'resource' => 'chargeback',
        'id' => $id,
        'amount' => ['value' => $amount, 'currency' => 'EUR'],
        'createdAt' => '2026-09-03T10:00:00+00:00',
        'reversedAt' => $reversed ? '2026-09-10T10:00:00+00:00' : null,
        'paymentId' => 'tr_first',
    ];
}
