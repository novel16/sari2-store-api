<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class StatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'admin_status_changes';

    protected $fillable = ['account_id', 'store_id', 'target_type', 'target_name_snapshot',
        'from_status', 'to_status', 'reason', 'actor_user_id', 'actor_name_snapshot',
        'request_key', 'request_hash', 'review_hash', 'created_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Administrative status history is immutable.'));
        static::deleting(fn () => throw new LogicException('Administrative status history must be retained.'));
    }
}
