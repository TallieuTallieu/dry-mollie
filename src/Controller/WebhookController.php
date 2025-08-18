<?php

namespace Tnt\Mollie\Controller;

use dry\http\Request;
use Mollie\Api\MollieApiClient;
use Tnt\Mollie\MolliePayment;

class WebhookController
{
    public static function process(
        Request $request,
        MollieApiClient $mollieApiClient
    ) {
        MolliePayment::process($mollieApiClient, $request->post->string('id'));
    }
}
