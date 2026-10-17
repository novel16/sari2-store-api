<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalePayment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['store_id', 'method', 'amount_centavos', 'tendered_centavos', 'change_centavos'];

    protected function casts(): array
    {
        return ['amount_centavos' => 'integer', 'tendered_centavos' => 'integer', 'change_centavos' => 'integer'];
    }
}
