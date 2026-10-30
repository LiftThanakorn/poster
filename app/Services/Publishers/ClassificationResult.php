<?php

namespace App\Services\Publishers;

class ClassificationResult
{
    public function __construct(
        public readonly string $category,
        public readonly bool $isRetryable,
        public readonly int $retryDelaySeconds,
        public readonly string $messageTh,
        public readonly ?string $errorCode = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $externalSubcode = null,
    ) {}
}
