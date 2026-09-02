<?php

declare(strict_types=1);

/*
 * Smoke only: the package installs next to dry-ecommerce 4.x, the gateway
 * satisfies the contract, and the provider registers its client. The real
 * behavioural suite — status mappings, replay idempotency, re-placement —
 * arrives with the reimplementation on the payment harness.
 */

use Mollie\Api\MollieApiClient;
use Oak\Container\Container;
use Tnt\Ecommerce\Contracts\PaymentInterface;
use Tnt\Mollie\MolliePayment;
use Tnt\Mollie\MollieServiceProvider;

it('implements the ecommerce payment contract', function (): void {
    expect(class_implements(MolliePayment::class))->toHaveKey(
        PaymentInterface::class
    );
});

it('registers the Mollie API client', function (): void {
    $app = new Container();

    (new MollieServiceProvider())->register($app);

    expect($app->has(MollieApiClient::class))->toBe(true);
});
