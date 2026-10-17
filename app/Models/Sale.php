<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    protected $with = ['reversal'];

    public function reversal(): HasOne
    {
        return $this->hasOne(SaleReversal::class);
    }

    protected $fillable = [
        'sold_by_user_id', 'sale_number', 'idempotency_key', 'request_hash', 'status',
        'store_name_snapshot', 'store_address_snapshot', 'store_phone_snapshot', 'sold_by_name_snapshot',
        'subtotal_centavos', 'discount_centavos', 'total_centavos', 'amount_paid_centavos',
        'items_count', 'quantity_total', 'completed_at',
        'payment_method', 'customer_id', 'customer_name_snapshot', 'customer_phone_snapshot', 'credit_amount_centavos',
    ];

    protected function casts(): array
    {
        return [
            'subtotal_centavos' => 'integer', 'discount_centavos' => 'integer', 'total_centavos' => 'integer',
            'amount_paid_centavos' => 'integer', 'items_count' => 'integer', 'quantity_total' => 'integer',
            'completed_at' => 'datetime',
            'credit_amount_centavos' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(SalePayment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function creditEntry(): HasOne
    {
        return $this->hasOne(CreditLedgerEntry::class);
    }
}
