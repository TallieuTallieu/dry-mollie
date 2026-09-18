<?php

declare(strict_types=1);

namespace Tnt\Mollie\Contracts;

use Mollie\Api\Exceptions\MollieException;
use Mollie\Api\MollieApiClient;

/**
 * Builds the Mollie API client, on demand.
 *
 * The client is not injected into the gateway, it is asked for: a missing
 * or malformed API key throws while the client is being built, and the
 * gateway needs that throw to happen inside its own try — not while the
 * container assembles it. See docs/gateway.md.
 */
interface MollieClientFactoryInterface
{
    /**
     * A client authenticated with the configured key.
     *
     * @return MollieApiClient
     *
     * @throws MollieException When the configured key is not a Mollie key.
     */
    public function make(): MollieApiClient;
}
