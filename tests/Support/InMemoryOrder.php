<?php

declare(strict_types=1);

namespace Tests\Support;

use Tnt\Ecommerce\Model\Order;

/**
 * An order that keeps to memory: everything is assigned through the real
 * model, only save() stops short of a connection. The count is what tells
 * a guarded no-op write apart from a write that happened.
 */
final class InMemoryOrder extends Order
{
    /**
     * How many times save() was called.
     *
     * @var int
     */
    public int $saveCount = 0;

    /**
     * @return mixed|void
     */
    public function save()
    {
        $this->saveCount++;

        if ($this->id === null) {
            $this->id = 1;
        }
    }
}
