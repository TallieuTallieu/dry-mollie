<?php

declare(strict_types=1);

namespace Tests\Support;

use Tnt\Ecommerce\Model\Order;
use Tnt\Ecommerce\Payment\PaymentWebhook;

/**
 * The production webhook handler over its findOrder() seam: the lookup that
 * would query `ecommerce_order.payment_id` answers from this map, and
 * everything else in handle() runs for real.
 */
final class InMemoryPaymentWebhook extends PaymentWebhook
{
    /**
     * The orders the "database" holds, keyed by payment id.
     *
     * @var array<string, Order>
     */
    public array $orders = [];

    /**
     * @param string $paymentId
     * @return Order|null
     */
    protected function findOrder(string $paymentId): ?Order
    {
        return $this->orders[$paymentId] ?? null;
    }
}
