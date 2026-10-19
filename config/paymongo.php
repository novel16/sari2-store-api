<?php

return [
    'enabled' => env('PAYMONGO_ENABLED', true),
    'mode' => env('PAYMONGO_MODE', 'test'),
    'secret_key' => env('PAYMONGO_SECRET_KEY'),
    'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),

    // Hosted Checkout only needs the secret key on the server, never the public key.
    'base_url' => 'https://api.paymongo.com',
    'connect_timeout' => 5,
    'timeout' => 15,
    'signature_tolerance' => 300,
    'checkout_lifetime_minutes' => 30,
];
