<?php

namespace App\Exceptions;

use App\Services\Publishers\PublishFailureClassifier;
use App\Support\SecretSanitizer;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

class PublishException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $channel,
        public readonly string $category,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $externalCode = null,
        public readonly ?string $externalSubcode = null,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(SecretSanitizer::sanitize($message), 0, $previous);
    }

    public static function fromHttpResponse(Response $response, string $channel, int $attempt = 1): self
    {
        $failure = PublishFailureClassifier::classifyHttpResponse($response, $channel, $attempt);

        return new self(
            message: $failure->messageTh,
            channel: $channel,
            category: $failure->category,
            retryable: $failure->isRetryable,
            httpStatus: $failure->httpStatus,
            externalCode: $failure->errorCode,
            externalSubcode: $failure->externalSubcode,
            context: [
                'response' => SecretSanitizer::sanitize($response->body()),
            ],
        );
    }

    public static function fromThrowable(Throwable $throwable, string $channel, int $attempt = 1): self
    {
        $failure = PublishFailureClassifier::classifyThrowable($throwable, $channel, $attempt);

        return new self(
            message: $failure->messageTh,
            channel: $channel,
            category: $failure->category,
            retryable: $failure->isRetryable,
            context: [
                'exception' => $throwable::class,
                'message' => SecretSanitizer::sanitizeThrowable($throwable),
            ],
            previous: $throwable,
        );
    }
}
