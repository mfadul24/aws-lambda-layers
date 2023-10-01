<?php

declare(strict_types=1);

namespace App\Http;

use Bref\Context\Context;
use Bref\Event\Http\HttpRequestEvent;
use Bref\Event\Http\Psr7Bridge;
use Generator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Spiral\RoadRunner\Http\HttpWorker;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\WorkerInterface;

class AwsHttpWorker extends PSR7Worker
{
    private HttpWorker $httpWorker;
    private StreamFactoryInterface $streamFactory;

    public function __construct(
        WorkerInterface $worker,
        ServerRequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        UploadedFileFactoryInterface $uploadsFactory
    ) {
        $this->httpWorker = new HttpWorker($worker);
        $this->streamFactory = $streamFactory;
        parent::__construct($worker, $requestFactory, $streamFactory, $uploadsFactory);
    }

    public function waitRequest(): ?ServerRequestInterface
    {
        $payload = $this->getWorker()->waitPayload();

        // Termination request
        if ($payload === null || (!$payload->body && !$payload->header)) {
            return null;
        }

        $event = (array) \json_decode($payload->body, true, 512, \JSON_THROW_ON_ERROR);
        $httpEvent = new HttpRequestEvent($event);
        $invokedFunctionArn = 'arn:aws:'.getenv('AWS_REGION').':'.($httpEvent->getRequestContext(
            )['accountId'] ?? '').':function:'.getenv('AWS_LAMBDA_FUNCTION_NAME');

        return Psr7Bridge::convertRequest(
            $httpEvent,
            new Context(
                $httpEvent->getRequestContext()['requestId'][0] ?? $httpEvent->getRequestContext()['requestId'] ?? '',
                30,
                $invokedFunctionArn,
                $httpEvent->getHeaders()['x-amzn-trace-id'][0] ?? $httpEvent->getHeaders()['x-amzn-trace-id'] ?? ''
            )
        );
    }

    public function respond(ResponseInterface $response): void
    {
        $awsResponse = Psr7Bridge::convertResponse($response);

        if ($this->chunkSize > 0) {
            $body = $this->streamFactory->createStream($awsResponse->toApiGatewayFormatV2());
            $this->httpWorker->respondStream(
                $response->getStatusCode(),
                $this->streamToGenerator($body),
                $response->getHeaders()
            );
        } else {
            $this->httpWorker->respond(
                $response->getStatusCode(),
                (string)\json_encode($awsResponse->toApiGatewayFormatV2(), JSON_THROW_ON_ERROR),
                $response->getHeaders());
        }
    }

    private function streamToGenerator(StreamInterface $stream): Generator
    {
        $stream->rewind();
        $size = $stream->getSize();
        if ($size !== null && $size < $this->chunkSize) {
            return (string)$stream;
        }
        $sum = 0;
        while (!$stream->eof()) {
            if ($size === null) {
                $chunk = $stream->read($this->chunkSize);
            } else {
                $left = $size - $sum;
                $chunk = $stream->read(\min($this->chunkSize, $left));
                if ($left <= $this->chunkSize && \strlen($chunk) === $left) {
                    return $chunk;
                }
            }
            $sum += \strlen($chunk);
            yield $chunk;
        }
    }
}
