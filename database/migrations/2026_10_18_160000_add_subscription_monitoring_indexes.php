<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['account_id', 'status', 'starts_at', 'id'], 'subscriptions_monitor_account_start');
            $table->index(['status', 'ends_at', 'account_id', 'starts_at', 'id'], 'subscriptions_monitor_remaining');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_monitor_account_start');
            $table->dropIndex('subscriptions_monitor_remaining');
        });
    }
};
