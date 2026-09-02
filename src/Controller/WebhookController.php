<?php

declare(strict_types=1);

namespace Tnt\Mollie\Controller;

use dry\http\Request;
use Mollie\Api\MollieApiClient;
use Tnt\Mollie\MolliePayment;

/**
 * Handles the Mollie webhook: hands the posted payment id to the gateway.
 */
class WebhookController
{
    /**
     * @param Request $request
     * @param MollieApiClient $mollieApiClient
     * @return void
     */
    public static function process(
        Request $request,
        MollieApiClient $mollieApiClient
    ): void {
        $paymentId = $request->post->string('id');

        MolliePayment::process(
            $mollieApiClient,
            is_string($paymentId) ? $paymentId : ''
        );
    }
}
