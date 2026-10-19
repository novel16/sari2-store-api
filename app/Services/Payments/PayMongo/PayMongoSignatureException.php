<?php

namespace App\Services\Payments\PayMongo;

use RuntimeException;

class PayMongoSignatureException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The PayMongo webhook signature is invalid.');
    }
}
