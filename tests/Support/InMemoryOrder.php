<?php

declare(strict_types=1);

namespace Tests\Support;

use Tnt\Ecommerce\Model\Order;
use Tnt\Ecommerce\Model\PaymentEntry;
use Tnt\Mollie\Contracts\PaysAtDeskInterface;
use Tnt\Mollie\DeskPayment;

/**
 * An order that keeps to memory: everything is assigned through the real
 * model, only save() stops short of a connection, and its payment history
 * is what {@see InMemoryPaymentLedger} wrote rather than a table read.
 * It pays online unless a test hands it a desk payment.
 */
final class InMemoryOrder extends Order implements PaysAtDeskInterface
{
    /**
     * @var DeskPayment|null
     */
    public ?DeskPayment $deskPayment = null;

    /**
     * @var list<PaymentEntry>
     */
    public array $entries = [];

    /**
     * @return mixed|void
     */
    public function save()
    {
        if ($this->id === null) {
            $this->id = 1;
        }
    }

    /**
     * @return list<PaymentEntry>
     */
    public function getPaymentEntries(): array
    {
        return $this->entries;
    }

    /**
     * @param PaymentEntry $entry
     * @return void
     */
    public function keepPaymentEntry(PaymentEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return DeskPayment|null
     */
    public function getDeskPayment(): ?DeskPayment
    {
        return $this->deskPayment;
    }
}
