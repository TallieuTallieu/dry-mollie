<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\Contracts\RetryStrategyContract;
use Mollie\Api\Http\LinearRetryStrategy;
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
     * What the Mollie client itself defaults to: five retries, waiting
     * a second longer each time. See docs/gateway.md on lowering it.
     */
    private const DEFAULT_RETRIES = 5;
    private const DEFAULT_RETRY_DELAY_MS = 1000;

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
        $client->setRetryStrategy($this->retryStrategy());

        return $client;
    }

    /**
     * How long a dropped connection may be re-tried before the gateway is
     * told it failed. It is the project's call because it is the project's
     * checkout that waits: the client sleeps through this inside `pay()`,
     * with a visitor watching.
     *
     * @return RetryStrategyContract
     */
    private function retryStrategy(): RetryStrategyContract
    {
        return new LinearRetryStrategy(
            $this->configuredInt('mollie.retries', self::DEFAULT_RETRIES),
            $this->configuredInt(
                'mollie.retry_delay_ms',
                self::DEFAULT_RETRY_DELAY_MS
            )
        );
    }

    /**
     * A whole number from configuration, or the default when it is unset
     * or is not one.
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    private function configuredInt(string $key, int $default): int
    {
        $configured = $this->config->get($key);

        return is_int($configured) ? $configured : $default;
    }
}
