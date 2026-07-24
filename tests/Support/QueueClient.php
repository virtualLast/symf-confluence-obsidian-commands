<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class QueueClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);

        if ($response === null) {
            throw new \LogicException(sprintf(
                'No queued response is available for %s %s.',
                $request->getMethod(),
                (string) $request->getUri(),
            ));
        }

        return $response;
    }
}
