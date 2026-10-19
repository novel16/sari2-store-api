<?php

namespace App\Services\Payments\PayMongo;

use RuntimeException;

class PayMongoPayloadException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('The PayMongo payload could not be verified: '.$reason.'.');
    }
}
