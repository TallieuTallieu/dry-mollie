<?php

declare(strict_types=1);

namespace Tests\Support;

use Nyholm\Psr7\Request;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * The shape of a connection that never got an answer — DNS, refused,
 * dropped. Mollie's mock adapter recognises a PSR NetworkExceptionInterface
 * and re-throws it as a RetryableNetworkRequestException, which is how the
 * real client reports it too.
 */
final class NetworkFailure extends \RuntimeException implements
    NetworkExceptionInterface
{
    /**
     * @return RequestInterface
     */
    public function getRequest(): RequestInterface
    {
        return new Request('POST', 'https://api.mollie.com/v2/payments');
    }
}
