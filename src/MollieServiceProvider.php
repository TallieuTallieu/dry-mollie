<?php

declare(strict_types=1);

namespace Tnt\Mollie;

use dry\http\Request;
use dry\route\Router;
use Mollie\Api\MollieApiClient;
use Oak\Contracts\Config\RepositoryInterface;
use Oak\Contracts\Container\ContainerInterface;
use Oak\ServiceProvider;
use Tnt\Mollie\Controller\WebhookController;

/**
 * Registers the Mollie API client and the 1.x webhook route. The route
 * moves to the project (dry routes are project-registered) when the
 * gateway is reimplemented on the payment harness.
 */
class MollieServiceProvider extends ServiceProvider
{
    /**
     * @param ContainerInterface $app
     * @return void
     */
    public function boot(ContainerInterface $app): void
    {
        Router::register('nl', null, [
            'mollie-webhook/' => function (Request $request) use ($app): void {
                /** @var MollieApiClient $client */
                $client = $app->get(MollieApiClient::class);

                WebhookController::process($request, $client);
            },
        ]);
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
