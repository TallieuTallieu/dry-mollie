<?php

declare(strict_types=1);

/*
 * The webhook half: reportOf() interrogates Mollie's API (never the webhook
 * body) and reports the payment's status and every money movement on it;
 * dry-ecommerce's PaymentWebhook hands that to the ledger, which writes what
 * is new and derives the order's status. The replay and late-arrival tests
 * run through the real ledger, because its dedupe on (provider, kind,
 * reference) is the whole idempotency story.
 */

use Mollie\Api\Exceptions\ServiceUnavailableException;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Fake\SequenceMockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use Tests\Support\InMemoryPaymentWebhook;
use Tnt\Ecommerce\Payment\EntryKind;
use Tnt\Ecommerce\Payment\Movement;
use Tnt\Ecommerce\Payment\PaymentReport;
use Tnt\Ecommerce\Payment\PaymentStatus;
use Tnt\Ecommerce\UnknownPayment;

/**
 * The report reportOf() gives for one mocked Mollie payment body.
 *
 * @param array<string, mixed> $body
 * @return PaymentReport
 */
function reportFor(array $body): PaymentReport
{
    $client = new MockMollieClient([
        GetPaymentRequest::class => MockResponse::ok($body),
    ]);

    return makeGateway($client)->reportOf('tr_first');
}

it('maps every Mollie status onto the package vocabulary', function (
    string $mollieStatus,
    PaymentStatus $expected
): void {
    $report = reportFor(molliePaymentBody('tr_first', $mollieStatus));

    expect($report->paymentId)->toBe('tr_first');
    expect($report->status)->toBe($expected);

    // No money moved on an unpaid payment.
    expect($report->movements)->toBe([]);
})->with([
    'open' => ['open', PaymentStatus::Pending],
    'pending' => ['pending', PaymentStatus::Pending],
    // Authorized is only a reservation: the capture can still fail or be
    // voided, so nothing is reported until Mollie says paid.
    'authorized' => ['authorized', PaymentStatus::Pending],
    'failed' => ['failed', PaymentStatus::Failed],
    'canceled' => ['canceled', PaymentStatus::Canceled],
    'expired' => ['expired', PaymentStatus::Expired],
]);

it('reports a paid payment with its capture', function (): void {
    expect(reportFor(paidMolliePaymentBody('tr_first')))->toEqual(
        new PaymentReport('tr_first', PaymentStatus::Paid, [
            new Movement(EntryKind::Captured, 'tr_first', 1250),
        ])
    );
});

it('asks for refunds and chargebacks in the same call', function (): void {
    $client = new MockMollieClient(
        [
            GetPaymentRequest::class => MockResponse::ok(
                paidMolliePaymentBody('tr_first')
            ),
        ],
        retainRequests: true
    );

    makeGateway($client)->reportOf('tr_first');

    $client->assertSent(
        fn(PendingRequest $request): bool => $request->query()->get('embed') ===
            'refunds,chargebacks'
    );
});

it('reports a refund once money moved, never as the status', function (
    string $refundStatus,
    ?EntryKind $expected
): void {
    $report = reportFor(
        paidMolliePaymentBody(
            'tr_first',
            refunds: [refund('re_1', $refundStatus, '1.00')]
        )
    );

    expect($report->status)->toBe(PaymentStatus::Paid);
    expect($report->movements)->toEqual(
        array_values(
            array_filter([
                new Movement(EntryKind::Captured, 'tr_first', 1250),
                $expected === null
                    ? null
                    : new Movement($expected, 're_1', 100),
            ])
        )
    );
})->with([
    // Still cancelable, or canceled: no money went back.
    'queued' => ['queued', null],
    'pending' => ['pending', null],
    'canceled' => ['canceled', null],
    'processing' => ['processing', EntryKind::Refunded],
    'refunded' => ['refunded', EntryKind::Refunded],
    // The ledger drops it unless the refund itself is already written.
    'failed' => ['failed', EntryKind::RefundReversed],
]);

it('reports every chargeback, and its reversal', function (): void {
    $report = reportFor(
        paidMolliePaymentBody(
            'tr_first',
            chargebacks: [
                chargeback('chb_1', '12.50'),
                chargeback('chb_2', '2.00', reversed: true),
            ]
        )
    );

    expect($report->movements)->toEqual([
        new Movement(EntryKind::Captured, 'tr_first', 1250),
        new Movement(EntryKind::Chargeback, 'chb_1', 1250),
        // Still reported once reversed: the reversal needs its counterpart.
        new Movement(EntryKind::Chargeback, 'chb_2', 200),
        new Movement(EntryKind::ChargebackReversed, 'chb_2', 200),
    ]);
});

it('lets a Mollie failure out of reportOf', function (): void {
    // The webhook needs it to answer non-2xx, so Mollie retries.
    $client = new MockMollieClient([
        GetPaymentRequest::class => MockResponse::error(
            503,
            'Service Unavailable',
            'The Mollie API is temporarily unavailable'
        ),
    ]);

    makeGateway($client)->reportOf('tr_first');
})->throws(ServiceUnavailableException::class);

it('moves the order through the package webhook and ledger', function (
    array $bodies,
    PaymentStatus $expected
): void {
    $client = new MockMollieClient([
        GetPaymentRequest::class => new SequenceMockResponse(
            ...array_map(fn(array $body) => MockResponse::ok($body), $bodies)
        ),
    ]);

    $ledger = makeLedger();
    $order = orderPayingWith($ledger, 'tr_first');
    $webhook = new InMemoryPaymentWebhook(makeGateway($client), $ledger);

    foreach ($bodies as $_) {
        $webhook->handle('tr_first');
    }

    expect($order->getPaymentStatus())->toBe($expected);
})->with([
    'paid' => [[paidMolliePaymentBody('tr_first')], PaymentStatus::Paid],
    // A late expired is recorded, and the money outranks it.
    'paid, then a late expired' => [
        [
            paidMolliePaymentBody('tr_first'),
            molliePaymentBody('tr_first', 'expired'),
        ],
        PaymentStatus::Paid,
    ],
    'paid, then partially refunded' => [
        [
            paidMolliePaymentBody('tr_first'),
            paidMolliePaymentBody(
                'tr_first',
                refunds: [refund('re_1', 'processing', '1.00')]
            ),
        ],
        PaymentStatus::PartiallyRefunded,
    ],
    'paid, then fully refunded' => [
        [
            paidMolliePaymentBody('tr_first'),
            paidMolliePaymentBody(
                'tr_first',
                refunds: [refund('re_1', 'refunded', '12.50')]
            ),
        ],
        PaymentStatus::Refunded,
    ],
    'a queued refund is no refund yet' => [
        [
            paidMolliePaymentBody(
                'tr_first',
                refunds: [refund('re_1', 'queued', '12.50')]
            ),
        ],
        PaymentStatus::Paid,
    ],
    'a refund that failed after it was recorded' => [
        [
            paidMolliePaymentBody(
                'tr_first',
                refunds: [refund('re_1', 'processing', '12.50')]
            ),
            paidMolliePaymentBody(
                'tr_first',
                refunds: [refund('re_1', 'failed', '12.50')]
            ),
        ],
        PaymentStatus::Paid,
    ],
    'fully charged back' => [
        [
            paidMolliePaymentBody(
                'tr_first',
                chargebacks: [chargeback('chb_1', '12.50')]
            ),
        ],
        PaymentStatus::Refunded,
    ],
    'a charged-back payment whose chargeback was reversed' => [
        [
            paidMolliePaymentBody(
                'tr_first',
                chargebacks: [chargeback('chb_1', '12.50')]
            ),
            paidMolliePaymentBody(
                'tr_first',
                chargebacks: [chargeback('chb_1', '12.50', reversed: true)]
            ),
        ],
        PaymentStatus::Paid,
    ],
]);

it('writes nothing for a replayed report', function (): void {
    $body = paidMolliePaymentBody(
        'tr_first',
        refunds: [refund('re_1', 'processing', '1.00')]
    );

    $client = new MockMollieClient([
        GetPaymentRequest::class => new SequenceMockResponse(
            MockResponse::ok($body),
            MockResponse::ok($body)
        ),
    ]);

    $ledger = makeLedger();
    orderPayingWith($ledger, 'tr_first');
    $webhook = new InMemoryPaymentWebhook(makeGateway($client), $ledger);

    $webhook->handle('tr_first');
    $afterFirst = $ledger->kinds();

    $webhook->handle('tr_first');

    expect($afterFirst)->toBe([
        'attempt_started',
        'captured',
        'refunded',
        'status_reported',
    ]);
    expect($ledger->kinds())->toBe($afterFirst);
});

it('refuses a payment id no order carries', function (): void {
    // No expected responses: the handler must refuse BEFORE asking Mollie —
    // any API call here would fail the test loudly.
    $client = new MockMollieClient([]);

    $ledger = makeLedger();
    $webhook = new InMemoryPaymentWebhook(makeGateway($client), $ledger);

    try {
        $webhook->handle('tr_never_issued');
    } finally {
        expect($ledger->kinds())->toBe(['unknown_payment']);
    }
})->throws(UnknownPayment::class, 'tr_never_issued');
