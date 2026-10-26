<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Tnt\Ecommerce\Model\Order;
use Tnt\Ecommerce\Model\PaymentEntry;
use Tnt\Ecommerce\Payment\PaymentLedger;

/**
 * The production ledger over its writes: entries land in memory and on the
 * order, and `payment_status` moves without a connection. Holds the table's
 * UNIQUE (`provider`, `kind`, `reference`) too, so a duplicate write fails
 * here the way it would in MySQL. Same as dry-ecommerce's own.
 */
final class InMemoryPaymentLedger extends PaymentLedger
{
    /**
     * Every entry written, in order — `unknown_payment` ones included.
     *
     * @var list<PaymentEntry>
     */
    public array $written = [];

    /**
     * @param PaymentEntry $entry
     * @return void
     */
    protected function write(PaymentEntry $entry): void
    {
        if ($entry->reference !== null) {
            foreach ($this->written as $existing) {
                if (
                    $existing->provider === $entry->provider &&
                    $existing->kind === $entry->kind &&
                    $existing->reference === $entry->reference
                ) {
                    throw new LogicException(
                        'Duplicate entry for UNIQUE (provider, kind, reference).'
                    );
                }
            }
        }

        $entry->id = count($this->written) + 1;
        $this->written[] = $entry;

        $order = $entry->order;

        if ($order instanceof InMemoryOrder) {
            $order->keepPaymentEntry($entry);
        }
    }

    /**
     * dry-ecommerce 3.14.1 moves `payment_status` with a conditional UPDATE;
     * one request at a time here, so the move always lands.
     *
     * @param Order $order
     * @param string|null $from
     * @param string $to
     * @return bool
     */
    protected function moveStatus(Order $order, ?string $from, string $to): bool
    {
        return true;
    }

    /**
     * The kinds written so far, in order — the shape most tests assert.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_map(
            static fn(PaymentEntry $entry): string => $entry->kind,
            $this->written
        );
    }
}
