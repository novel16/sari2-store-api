<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AdministratorEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'admin_administrator_events';

    protected $fillable = ['administrator_user_id', 'administrator_name_snapshot', 'administrator_email_snapshot',
        'action', 'from_status', 'to_status', 'reason', 'actor_user_id', 'actor_name_snapshot',
        'request_key', 'request_hash', 'review_hash', 'result_snapshot', 'created_at'];

    protected function casts(): array
    {
        return ['result_snapshot' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Administrator history is immutable.'));
        static::deleting(fn () => throw new LogicException('Administrator history must be retained.'));
    }
}
