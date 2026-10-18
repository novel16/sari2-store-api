<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->unsignedBigInteger('status_revision')->default(0));
        Schema::table('stores', function (Blueprint $table) {
            $table->unsignedBigInteger('status_revision')->default(0);
            $table->unique(['account_id', 'id'], 'stores_account_id_id_unique');
        });

        Schema::create('admin_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->foreign(['account_id', 'store_id'])->references(['account_id', 'id'])->on('stores')->restrictOnDelete();
            $table->string('target_type', 20);
            $table->string('target_name_snapshot');
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason', 500);
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name_snapshot');
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->char('review_hash', 64)->unique();
            $table->timestamp('created_at');
            $table->index(['account_id', 'target_type', 'id'], 'status_changes_account_history');
            $table->index(['store_id', 'id'], 'status_changes_store_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_status_changes');
        Schema::table('stores', function (Blueprint $table) {
            $table->dropUnique('stores_account_id_id_unique');
            $table->dropColumn('status_revision');
        });
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('status_revision'));
    }
};
