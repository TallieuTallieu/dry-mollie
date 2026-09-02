<?php

declare(strict_types=1);

namespace Tests\Support;

use Oak\Container\Container;

/**
 * A container that says it is not running in a console, so booting
 * EcommerceServiceProvider registers its listeners without dragging the
 * migrator in. Same trick as dry-ecommerce's own test suite.
 */
final class WebContainer extends Container
{
    /**
     * @return bool
     */
    public function isRunningInConsole(): bool
    {
        return false;
    }
}
