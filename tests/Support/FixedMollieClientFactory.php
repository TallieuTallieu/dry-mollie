<?php

declare(strict_types=1);

namespace Tests\Support;

use Mollie\Api\MollieApiClient;
use Tnt\Mollie\Contracts\MollieClientFactoryInterface;

/**
 * Hands out a client that is already built — the mock one the test wired.
 * Building is what the real factory does; a test that wants the building
 * itself to fail uses the real MollieClientFactory with a bad key.
 */
final class FixedMollieClientFactory implements MollieClientFactoryInterface
{
    /**
     * @param MollieApiClient $client
     */
    public function __construct(private MollieApiClient $client) {}

    /**
     * @return MollieApiClient
     */
    public function make(): MollieApiClient
    {
        return $this->client;
    }
}
