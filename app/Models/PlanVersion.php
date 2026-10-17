<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PlanVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['plan_id', 'version', 'terms', 'actor_user_id', 'change_note'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'terms' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Plan versions are immutable. Create a new version.'));
        static::deleting(fn () => throw new LogicException('Plan version history must be retained.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
