<?php

namespace Tnt\Mollie;

use dry\route\Router;
use Mollie\Api\MollieApiClient;
use Oak\Contracts\Config\RepositoryInterface;
use Oak\Contracts\Container\ContainerInterface;
use Oak\ServiceProvider;
use Tnt\Mollie\Controller\WebhookController;

class MollieServiceProvider extends ServiceProvider
{
    public function boot(ContainerInterface $app)
    {
        Router::register('nl', null, [
            'mollie-webhook/' => function($request) use ($app) {
                call_user_func_array(
                    [WebhookController::class, 'process',],
                    [$request, $app->get(MollieApiClient::class),]
                );
            }
        ]);
    }

    public function register(ContainerInterface $app)
    {
        $app->set(MollieApiClient::class, function($app) {

            $mollie = new MollieApiClient();
            $mollie->setApiKey($app->get(RepositoryInterface::class)->get('mollie.api_key'));

            return $mollie;
        });
    }
}