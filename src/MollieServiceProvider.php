<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use Mollie\Api\MollieApiClient;
use Oak\Contracts\Config\RepositoryInterface;
use Oak\Contracts\Container\ContainerInterface;
use Oak\ServiceProvider;

/**
 * Registers the Mollie API client. The webhook route is the project's to
 * wire — one route to dry-ecommerce's PaymentWebhook; see docs/gateway.md.
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
        $app->set(MollieApiClient::class, function (
            ContainerInterface $container
        ): MollieApiClient {
            /** @var RepositoryInterface $config */
            $config = $container->get(RepositoryInterface::class);

            $apiKey = $config->get('mollie.api_key');

            $client = new MollieApiClient();
            $client->setApiKey(is_string($apiKey) ? $apiKey : '');

            return $client;
        });
    }
}
