<?php

declare(strict_types=1);

/*
 * The factory: the client is built late, so a key Mollie refuses throws
 * here — inside pay()'s try — rather than while the container assembles
 * the gateway. The retry budget it sets is the project's to decide,
 * because it is the project's checkout that waits it out.
 */

use Mollie\Api\Contracts\RetryStrategyContract;
use Mollie\Api\Exceptions\InvalidAuthenticationException;
use Mollie\Api\MollieApiClient;
use Oak\Config\Repository;
use Tnt\Mollie\MollieClientFactory;

/**
 * The retry strategy a built client carries. The client keeps it to
 * itself, and only the strategy says what a failing connection costs.
 *
 * @param MollieApiClient $client
 * @return RetryStrategyContract
 */
function retryStrategyOf(MollieApiClient $client): RetryStrategyContract
{
    $property = new ReflectionProperty(MollieApiClient::class, 'retryStrategy');

    $strategy = $property->getValue($client);

    expect($strategy)->toBeInstanceOf(RetryStrategyContract::class);

    /** @var RetryStrategyContract $strategy */
    return $strategy;
}

it('retries as often as the client does by default', function (): void {
    $factory = new MollieClientFactory(
        new Repository([
            'mollie' => ['api_key' => 'test_' . str_repeat('x', 30)],
        ])
    );

    $strategy = retryStrategyOf($factory->make());

    expect($strategy->maxRetries())->toBe(5);
    expect($strategy->delayBeforeAttemptMs(1))->toBe(1000);
});

it('lets the project set the retry budget', function (): void {
    // A checkout has a visitor waiting on it: a project may prefer to fail
    // fast over sitting out the default fifteen seconds.
    $factory = new MollieClientFactory(
        new Repository([
            'mollie' => [
                'api_key' => 'test_' . str_repeat('x', 30),
                'retries' => 1,
                'retry_delay_ms' => 250,
            ],
        ])
    );

    $strategy = retryStrategyOf($factory->make());

    expect($strategy->maxRetries())->toBe(1);
    expect($strategy->delayBeforeAttemptMs(1))->toBe(250);
});

it('refuses a key that is not a Mollie key', function (): void {
    // The throw pay() is built around: it happens when the client is made,
    // not when the gateway is assembled.
    $factory = new MollieClientFactory(
        new Repository(['mollie' => ['api_key' => 'not-a-mollie-key']])
    );

    $factory->make();
})->throws(InvalidAuthenticationException::class);
