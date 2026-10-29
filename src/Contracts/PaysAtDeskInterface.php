<?php

declare(strict_types=1);

namespace Tnt\Mollie\Contracts;

use Tnt\Mollie\DeskPayment;

/**
 * An order that may be paid at the shop's desk rather than online. pay()
 * asks the order, since dry-ecommerce hands it nothing else. See
 * docs/gateway.md, "At the desk".
 */
interface PaysAtDeskInterface
{
    /**
     * How the desk takes the money, or null for an online payment.
     *
     * @return DeskPayment|null
     */
    public function getDeskPayment(): ?DeskPayment;
}
