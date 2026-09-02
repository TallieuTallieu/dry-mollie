<?php

declare(strict_types=1);

namespace Tests\Support;

use Tnt\Ecommerce\Contracts\RedirectorInterface;

/**
 * A redirector that records where it was told to send the visitor instead
 * of sending headers and exiting — what lets pay() run to the end of a
 * test.
 */
final class FakeRedirector implements RedirectorInterface
{
    /**
     * Every URL redirect() was asked for, in order.
     *
     * @var array<int, string>
     */
    public array $sentTo = [];

    /**
     * @param string $url
     * @return void
     */
    public function redirect(string $url): void
    {
        $this->sentTo[] = $url;
    }
}
