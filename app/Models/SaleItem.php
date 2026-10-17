<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'store_id', 'product_id', 'product_name_snapshot', 'sku_snapshot', 'barcode_snapshot', 'unit_snapshot',
        'quantity', 'unit_price_centavos', 'unit_cost_centavos', 'line_total_centavos',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_centavos' => 'integer', 'unit_cost_centavos' => 'integer', 'line_total_centavos' => 'integer'];
    }
}
