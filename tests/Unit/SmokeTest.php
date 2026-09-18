<?php

declare(strict_types=1);

/*
 * Smoke: the package installs next to dry-ecommerce's payment ledger, the gateway
 * satisfies the payment contracts, and the provider registers what the
 * gateway is assembled from. The behavioural suite lives in tests/Feature.
 */

use Mollie\Api\MollieApiClient;
use Oak\Config\Repository;
use Oak\Container\Container;
use Oak\Contracts\Config\RepositoryInterface;
use Tnt\Ecommerce\Contracts\PaymentGatewayInterface;
use Tnt\Ecommerce\Contracts\PaymentInterface;
use Tnt\Mollie\Contracts\MollieClientFactoryInterface;
use Tnt\Mollie\MolliePayment;
use Tnt\Mollie\MollieServiceProvider;

it('implements the ecommerce payment contracts', function (): void {
    expect(class_implements(MolliePayment::class))
        ->toHaveKey(PaymentInterface::class)
        ->toHaveKey(PaymentGatewayInterface::class);
});

it('registers the client factory, and the client itself', function (): void {
    $app = new Container();

    (new MollieServiceProvider())->register($app);

    expect($app->has(MollieClientFactoryInterface::class))->toBe(true);
    expect($app->has(MollieApiClient::class))->toBe(true);
});

it('assembles the gateway without reading the API key', function (): void {
    // The key is only read in pay(), where a bad one ends as a refusal.
    $app = new Container();

    $app->set(
        RepositoryInterface::class,
        fn(): Repository => new Repository([
            'mollie' => ['api_key' => 'not-a-mollie-key'],
        ])
    );

    (new MollieServiceProvider())->register($app);

    expect($app->get(MolliePayment::class))->toBeInstanceOf(
        MolliePayment::class
    );
});
