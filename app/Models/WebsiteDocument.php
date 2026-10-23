<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'version' => 'integer',
            'published_version' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
