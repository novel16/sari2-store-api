<?php

namespace App\Services\Payments\PayMongo;

use RuntimeException;

class PayMongoRequestException extends RuntimeException
{
    public function __construct(
        public readonly string $operation,
        public readonly ?int $status = null,
        public readonly ?string $requestId = null,
    ) {
        // Do not chain Guzzle's exception: its message may include credentials or response bodies.
        parent::__construct('PayMongo could not complete the '.$operation.' operation.');
    }

    public function context(): array
    {
        return ['operation' => $this->operation, 'status' => $this->status, 'request_id' => $this->requestId];
    }
}
