<?php

namespace Tnt\Mollie;

use dry\route\Router;
use Mollie\Api\MollieApiClient;
use Oak\Contracts\Config\RepositoryInterface;
use Oak\Contracts\Container\ContainerInterface;
use Oak\ServiceProvider;
use Tnt\Mollie\Controller\WebhookController;

/**
 * Service provider for the Mollie payment integration.
 *
 * Registers the Mollie API client in the container and sets up webhook routing
 * for processing Mollie payment status updates.
 */
class MollieServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the Mollie payment service.
     *
     * Registers webhook routes for handling Mollie payment status updates.
     * The webhook endpoint is configured to accept POST requests from Mollie
     * and process payment state changes.
     *
     * @param ContainerInterface $app The service container
     * @return void
     */
    public function boot(ContainerInterface $app): void
    {
        Router::register('nl', null, [
            'mollie-webhook/' => function($webhookRequest) use ($app) {
                call_user_func_array(
                    [WebhookController::class, 'process',],
                    [$webhookRequest, $app->get(MollieApiClient::class),]
                );
            }
        ]);
    }

    /**
     * Register services in the container.
     *
     * Configures and registers the Mollie API client with the API key
     * from the application configuration. The client is registered as
     * a singleton for efficient reuse throughout the application.
     *
     * @param ContainerInterface $app The service container
     * @return void
     */
    public function register(ContainerInterface $app): void
    {
        $app->set(MollieApiClient::class, function($container) {

            $mollieApiClient = new MollieApiClient();
            $mollieApiClient->setApiKey($container->get(RepositoryInterface::class)->get('mollie.api_key'));

            return $mollieApiClient;
        });
    }
}