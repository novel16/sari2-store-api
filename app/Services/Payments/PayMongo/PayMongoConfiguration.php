<?php

namespace App\Services\Payments\PayMongo;

class PayMongoConfiguration
{
    public function ready(): bool
    {
        try {
            $this->ensureReady();

            return true;
        } catch (PayMongoConfigurationException) {
            return false;
        }
    }

    public function ensureReady(): void
    {
        if (config('paymongo.enabled') !== true) {
            throw new PayMongoConfigurationException;
        }

        $this->ensureApiReady();
    }

    public function ensureApiReady(): void
    {
        $this->ensureWebhookReady();
        $key = config('paymongo.secret_key');
        $prefix = $this->liveMode() ? 'sk_live_' : 'sk_test_';

        if (! is_string($key) || ! preg_match('/^'.preg_quote($prefix, '/').'[A-Za-z0-9]+$/D', $key)) {
            throw new PayMongoConfigurationException;
        }
    }

    public function ensureWebhookReady(): void
    {
        $secret = config('paymongo.webhook_secret');

        if (! in_array(config('paymongo.mode'), ['test', 'live'], true)
            || ! is_string($secret) || trim($secret) === '') {
            throw new PayMongoConfigurationException;
        }
    }

    public function liveMode(): bool
    {
        return config('paymongo.mode') === 'live';
    }
}
