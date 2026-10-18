<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->unsignedBigInteger('admin_status_revision')->default(0));

        // The single row serializes roster changes, including the initial console administrator.
        Schema::create('admin_management_locks', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('revision')->default(0);
        });
        DB::table('admin_management_locks')->insert(['id' => 1, 'revision' => 0]);

        Schema::create('admin_administrator_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('administrator_user_id')->constrained('users')->restrictOnDelete();
            $table->string('administrator_name_snapshot');
            $table->string('administrator_email_snapshot');
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason', 500);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('actor_name_snapshot');
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->char('review_hash', 64)->nullable()->unique();
            $table->json('result_snapshot');
            $table->timestamp('created_at');
            $table->index(['administrator_user_id', 'id'], 'administrator_events_target_history');
            $table->index(['actor_user_id', 'request_key'], 'administrator_events_actor_operation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_administrator_events');
        Schema::dropIfExists('admin_management_locks');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('admin_status_revision'));
    }
};
