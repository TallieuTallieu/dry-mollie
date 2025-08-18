<?php

namespace Tnt\Mollie\Controller;

use dry\http\Request;
use Mollie\Api\MollieApiClient;
use Tnt\Mollie\MolliePayment;

/**
 * Controller for handling Mollie payment webhooks.
 */
class WebhookController
{
     /**
      * Process a Mollie payment webhook notification.
      *
      * Handles incoming webhook requests from Mollie to update payment status
      * and trigger appropriate events based on the payment state.
      *
      * @param Request $request The incoming HTTP request containing payment data
      * @param MollieApiClient $mollieApiClient The configured Mollie API client
      * @return void
      */
     public static function process(
         Request $request,
         MollieApiClient $mollieApiClient
     ): void {
         MolliePayment::process($mollieApiClient, $request->post->string('id'));
     }
}
