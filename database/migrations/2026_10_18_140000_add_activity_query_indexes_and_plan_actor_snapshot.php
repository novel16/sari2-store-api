<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCES = [
        'admin_administrator_events' => 'activity_administrators',
        'admin_status_changes' => 'activity_status',
        'plan_versions' => 'activity_plans',
        'subscription_activations' => 'activity_subscriptions',
    ];

    public function up(): void
    {
        // A current name cannot establish who was recorded on an older version.
        // Existing rows stay null; future plan saves capture their actor's name.
        Schema::table('plan_versions', fn (Blueprint $table) => $table->string('actor_name_snapshot')->nullable());

        foreach (self::SOURCES as $source => $index) {
            Schema::table($source, function (Blueprint $table) use ($index) {
                $table->index(['created_at', 'id'], $index.'_time');
                $table->index(['actor_user_id', 'created_at', 'id'], $index.'_actor_time');
            });
        }
    }

    public function down(): void
    {
        foreach (self::SOURCES as $source => $index) {
            Schema::table($source, function (Blueprint $table) use ($index) {
                $table->dropIndex($index.'_time');
                $table->dropIndex($index.'_actor_time');
            });
        }

        Schema::table('plan_versions', fn (Blueprint $table) => $table->dropColumn('actor_name_snapshot'));
    }
};
