<?php

declare(strict_types=1);

/*
 * Smoke: the package installs next to dry-ecommerce 4.x, the gateway
 * satisfies the harness contracts, and the provider registers its client.
 * The behavioural suite lives in tests/Feature.
 */

use Mollie\Api\MollieApiClient;
use Oak\Container\Container;
use Tnt\Ecommerce\Contracts\PaymentGatewayInterface;
use Tnt\Ecommerce\Contracts\PaymentInterface;
use Tnt\Mollie\MolliePayment;
use Tnt\Mollie\MollieServiceProvider;

it('implements the ecommerce payment contracts', function (): void {
    expect(class_implements(MolliePayment::class))
        ->toHaveKey(PaymentInterface::class)
        ->toHaveKey(PaymentGatewayInterface::class);
});

it('registers the Mollie API client', function (): void {
    $app = new Container();

    (new MollieServiceProvider())->register($app);

    expect($app->has(MollieApiClient::class))->toBe(true);
});
