<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'store_id', 'actor_user_id', 'movement_type', 'quantity_delta', 'quantity_before',
        'quantity_after', 'version_after', 'reason', 'request_key', 'request_hash', 'sale_id', 'sale_reversal_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer', 'quantity_before' => 'integer',
            'quantity_after' => 'integer', 'version_after' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reversal(): BelongsTo
    {
        return $this->belongsTo(SaleReversal::class, 'sale_reversal_id');
    }
}
