<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReversal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'sale_id', 'actor_user_id', 'actor_name_snapshot', 'request_key', 'request_hash',
        'kind', 'reason', 'restock', 'total_centavos', 'cash_refund_centavos', 'credit_reversed_centavos',
        'balance_before_centavos', 'balance_after_centavos', 'created_at'];

    protected function casts(): array
    {
        return ['restock' => 'boolean', 'total_centavos' => 'integer', 'cash_refund_centavos' => 'integer',
            'credit_reversed_centavos' => 'integer', 'balance_before_centavos' => 'integer', 'balance_after_centavos' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
