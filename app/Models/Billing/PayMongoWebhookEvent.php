<?php

namespace App\Models\Billing;

use Illuminate\Database\Eloquent\Model;

class PayMongoWebhookEvent extends Model
{
    protected $table = 'paymongo_webhook_events';

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'payload_hash'];

    protected function casts(): array
    {
        // Webhooks may contain billing details. Keep their durable inbox encrypted
        // and expose only safe event identifiers/statuses in operational output.
        return ['payload' => 'encrypted:array', 'livemode' => 'boolean', 'attempts' => 'integer',
            'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }
}
