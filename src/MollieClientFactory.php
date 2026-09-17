<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\MollieApiClient;
use Oak\Contracts\Config\RepositoryInterface;
use Tnt\Mollie\Contracts\MollieClientFactoryInterface;

/**
 * The configured Mollie client, built fresh each time it is asked for.
 *
 * `setApiKey()` constructs an `ApiKeyAuthenticator`, which refuses anything
 * that is not a `test_`/`live_` key with an `InvalidAuthenticationException`.
 * Building late is what puts that throw where a caller can catch it.
 */
class MollieClientFactory implements MollieClientFactoryInterface
{
    /**
     * @param RepositoryInterface $config
     */
    public function __construct(private RepositoryInterface $config) {}

    /**
     * @inheritDoc
     */
    public function make(): MollieApiClient
    {
        $apiKey = $this->config->get('mollie.api_key');

        $client = new MollieApiClient();
        $client->setApiKey(is_string($apiKey) ? $apiKey : '');

        return $client;
    }
}
