<?php

namespace App\Services\Payments\PayMongo;

use RuntimeException;

class PayMongoConfigurationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('PayMongo is not configured for secure subscription payments.');
    }
}
