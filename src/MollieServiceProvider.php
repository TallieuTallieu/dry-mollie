<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\MollieApiClient;
use Oak\Contracts\Container\ContainerInterface;
use Oak\ServiceProvider;
use Tnt\Mollie\Contracts\MollieClientFactoryInterface;

/**
 * Registers the Mollie client factory. The webhook route is the project's
 * to wire — one route to dry-ecommerce's PaymentWebhook; see
 * docs/gateway.md.
 */
class MollieServiceProvider extends ServiceProvider
{
    /**
     * @param ContainerInterface $app
     * @return void
     */
    public function boot(ContainerInterface $app): void
    {
        //
    }

    /**
     * @param ContainerInterface $app
     * @return void
     */
    public function register(ContainerInterface $app): void
    {
        $app->set(
            MollieClientFactoryInterface::class,
            MollieClientFactory::class
        );

        // Kept for project code that asks for the client itself. Resolving
        // it throws on a malformed API key, which is why the gateway goes
        // through the factory instead.
        $app->set(MollieApiClient::class, function (
            ContainerInterface $container
        ): MollieApiClient {
            /** @var MollieClientFactoryInterface $factory */
            $factory = $container->get(MollieClientFactoryInterface::class);

            return $factory->make();
        });
    }
}
