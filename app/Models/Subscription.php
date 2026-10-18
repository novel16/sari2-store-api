<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    protected $fillable = ['account_id', 'plan_id', 'plan_version_id', 'status', 'starts_at', 'ends_at', 'renews_at', 'provider', 'provider_reference'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'renews_at' => 'datetime'];
    }

    public function scopeCoveringAt(Builder $query, CarbonInterface $at): Builder
    {
        return $query->where('status', 'active')->where('starts_at', '<=', $at)
            ->where(fn (Builder $ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }
}
