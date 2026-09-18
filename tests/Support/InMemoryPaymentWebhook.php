<?php

declare(strict_types=1);

namespace Tests\Support;

use Tnt\Ecommerce\Contracts\PaymentGatewayInterface;
use Tnt\Ecommerce\Model\Order;
use Tnt\Ecommerce\Payment\PaymentWebhook;

/**
 * The production webhook handler over its findOrder() seam: the lookup that
 * would query `ecommerce_payment_entry` reads the in-memory ledger's entries
 * instead, and everything else in handle() runs for real.
 */
final class InMemoryPaymentWebhook extends PaymentWebhook
{
    /**
     * @param PaymentGatewayInterface $gateway
     * @param InMemoryPaymentLedger $entries
     */
    public function __construct(
        PaymentGatewayInterface $gateway,
        private InMemoryPaymentLedger $entries
    ) {
        parent::__construct($gateway, $entries);
    }

    /**
     * The same question the repository asks: the first entry under this
     * provider and payment id that belongs to an order.
     *
     * @param string $provider
     * @param string $paymentId
     * @return Order|null
     */
    protected function findOrder(string $provider, string $paymentId): ?Order
    {
        foreach ($this->entries->written as $entry) {
            if (
                $entry->provider === $provider &&
                $entry->payment_id === $paymentId &&
                $entry->order !== null
            ) {
                return $entry->order;
            }
        }

        return null;
    }
}
