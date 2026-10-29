<?php

declare(strict_types=1);

/*
 * pay() for an order placed at the shop's desk: cash is settled on the spot
 * without Mollie, the terminal gets a point-of-sale payment and the cashier
 * waits on the shop's own return page. The webhook settles a terminal
 * payment like any other.
 */

use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Tnt\Ecommerce\Payment\EntryKind;
use Tnt\Ecommerce\Payment\PaymentRedirect;
use Tnt\Ecommerce\Payment\PaymentRefused;
use Tnt\Ecommerce\Payment\PaymentSettled;
use Tnt\Ecommerce\Payment\PaymentStatus;
use Tnt\Mollie\DeskPayment;

it('settles cash on the spot for the whole total', function (): void {
    // No expected responses: any API call would fail the test loudly.
    $order = orderAwaitingPayment();
    $order->deskPayment = DeskPayment::Cash;

    $outcome = makeGateway(new MockMollieClient([]))->pay($order);

    expect($outcome)->toBeInstanceOf(PaymentSettled::class);

    /** @var PaymentSettled $outcome */
    $report = $outcome->report;

    expect($report->paymentId)->toStartWith('cash_');
    expect($report->status)->toBe(PaymentStatus::Paid);
    expect($report->movements)->toHaveCount(1);
    expect($report->movements[0]->kind)->toBe(EntryKind::Captured);
    expect($report->movements[0]->reference)->toBe($report->paymentId);
    expect($report->movements[0]->amount)->toBe(1250);
    expect($report->method)->toBe('cash');
    expect($report->cardLabel)->toBeNull();
});

it(
    'reads a cash order as paid in cash once the ledger has it',
    function (): void {
        $ledger = makeLedger();

        $order = orderAwaitingPayment();
        $order->deskPayment = DeskPayment::Cash;

        $ledger->start(
            $order,
            'mollie',
            makeGateway(new MockMollieClient([]))->pay($order)
        );

        expect($order->getPaymentStatus())->toBe(PaymentStatus::Paid);
        expect($order->getPaymentMethod())->toBe('cash');
    }
);

it('settles a free cash order as free, with no method', function (): void {
    $order = orderAwaitingPayment();
    $order->total = 0;
    $order->deskPayment = DeskPayment::Cash;

    /** @var PaymentSettled $outcome */
    $outcome = makeGateway(new MockMollieClient([]))->pay($order);

    expect($outcome->report->paymentId)->toStartWith('free_');
    expect($outcome->report->method)->toBeNull();
});

it('mints a fresh cash payment per placement', function (): void {
    $gateway = makeGateway(new MockMollieClient([]));

    $order = orderAwaitingPayment();
    $order->deskPayment = DeskPayment::Cash;

    /** @var PaymentSettled $first */
    $first = $gateway->pay($order);
    /** @var PaymentSettled $second */
    $second = $gateway->pay($order);

    expect($first->report->paymentId)->not->toBe($second->report->paymentId);
});

it('sends a terminal payment to the configured terminal', function (): void {
    $order = orderAwaitingPayment();
    $order->deskPayment = DeskPayment::Terminal;

    $payload = createdPaymentPayload(
        ['terminal_id' => 'term_7MgL4wea46qkRcoTZjWEH'],
        $order
    );

    expect($payload['method'] ?? null)->toBe('pointofsale');
    expect($payload['terminalId'] ?? null)->toBe('term_7MgL4wea46qkRcoTZjWEH');
    expect($payload['description'] ?? null)->toBe('7-K4M7QX9RTB');
    expect($payload['webhookUrl'] ?? null)->toBe(
        'https://shop.example/mollie-webhook/'
    );

    // Nothing for a checkout the customer never sees.
    expect($payload)->not->toHaveKeys([
        'redirectUrl',
        'cancelUrl',
        'locale',
        'billingAddress',
    ]);
});

it(
    'sends the cashier to the return page to wait for the terminal',
    function (): void {
        // Mollie gives a point-of-sale payment no checkout link.
        $client = new MockMollieClient([
            CreatePaymentRequest::class => MockResponse::created(
                terminalPaymentBody('tr_desk', 'open')
            ),
        ]);

        $order = orderAwaitingPayment();
        $order->deskPayment = DeskPayment::Terminal;

        $outcome = makeGateway($client, [
            'terminal_id' => 'term_7MgL4wea46qkRcoTZjWEH',
        ])->pay($order);

        expect($outcome)->toEqual(
            new PaymentRedirect(
                'tr_desk',
                'https://shop.example/checkout/return/?order=7'
            )
        );
    }
);

it(
    'refuses a terminal payment when no terminal is configured',
    function (): void {
        // No expected responses: Mollie must not be asked.
        $order = orderAwaitingPayment();
        $order->deskPayment = DeskPayment::Terminal;

        expect(makeGateway(new MockMollieClient([]))->pay($order))->toEqual(
            new PaymentRefused()
        );
    }
);

it('settles a paid terminal payment from the webhook', function (): void {
    $client = new MockMollieClient([
        CreatePaymentRequest::class => MockResponse::created(
            terminalPaymentBody('tr_desk', 'open')
        ),
        GetPaymentRequest::class => MockResponse::ok(
            terminalPaymentBody('tr_desk', 'paid', [
                'paidAt' => '2026-09-01T10:01:00+00:00',
                'details' => ['cardLabel' => 'Maestro'],
            ])
        ),
    ]);

    $gateway = makeGateway($client, [
        'terminal_id' => 'term_7MgL4wea46qkRcoTZjWEH',
    ]);
    $ledger = makeLedger();

    $order = orderAwaitingPayment();
    $order->deskPayment = DeskPayment::Terminal;

    $ledger->start($order, 'mollie', $gateway->pay($order));

    expect($order->getPaymentStatus())->toBe(PaymentStatus::Pending);

    $ledger->apply($order, 'mollie', $gateway->reportOf('tr_desk'));

    expect($order->getPaymentStatus())->toBe(PaymentStatus::Paid);
    expect($order->getPaymentMethod())->toBe('pointofsale');
    expect($order->getPaymentCardLabel())->toBe('Maestro');
});

it(
    'reports a terminal payment the customer cancels as canceled',
    function (): void {
        $client = new MockMollieClient([
            CreatePaymentRequest::class => MockResponse::created(
                terminalPaymentBody('tr_desk', 'open')
            ),
            GetPaymentRequest::class => MockResponse::ok(
                terminalPaymentBody('tr_desk', 'canceled', [
                    'canceledAt' => '2026-09-01T10:01:00+00:00',
                ])
            ),
        ]);

        $gateway = makeGateway($client, [
            'terminal_id' => 'term_7MgL4wea46qkRcoTZjWEH',
        ]);
        $ledger = makeLedger();

        $order = orderAwaitingPayment();
        $order->deskPayment = DeskPayment::Terminal;

        $ledger->start($order, 'mollie', $gateway->pay($order));
        $ledger->apply($order, 'mollie', $gateway->reportOf('tr_desk'));

        expect($order->getPaymentStatus())->toBe(PaymentStatus::Canceled);
    }
);

/**
 * A Mollie point-of-sale payment as the API answers it: no checkout link.
 *
 * @param string $id
 * @param string $status
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function terminalPaymentBody(
    string $id,
    string $status,
    array $overrides = []
): array {
    return molliePaymentBody(
        $id,
        $status,
        array_merge(
            [
                'method' => 'pointofsale',
                '_links' => [
                    'self' => [
                        'href' => 'https://api.mollie.com/v2/payments/' . $id,
                        'type' => 'application/hal+json',
                    ],
                ],
            ],
            $overrides
        )
    );
}
