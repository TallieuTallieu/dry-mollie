<?php

declare(strict_types=1);

namespace Tnt\Mollie;

/**
 * How an order placed at the shop's desk is paid. Each value is the method
 * the ledger's capture is filed with. See docs/gateway.md, "At the desk".
 */
enum DeskPayment: string
{
    /**
     * On the shop's Mollie terminal: a point-of-sale payment that settles
     * through the webhook, like an online one.
     */
    case Terminal = 'pointofsale';

    /**
     * In cash, at the desk: settled on the spot, Mollie never hears of it.
     */
    case Cash = 'cash';
}
