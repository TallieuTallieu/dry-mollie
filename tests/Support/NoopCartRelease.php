<?php

declare(strict_types=1);

namespace Tests\Support;

use Tnt\Ecommerce\Cart\CartRelease;
use Tnt\Ecommerce\Model\Order;

/**
 * The Paid listener resolves CartRelease out of the container, and the real
 * one queries `ecommerce_cart`. These tests are about the gateway, not the
 * release, so the release does nothing here.
 */
final class NoopCartRelease extends CartRelease
{
    /**
     * @param Order $order
     * @return void
     */
    public function release(Order $order): void
    {
        //
    }
}
