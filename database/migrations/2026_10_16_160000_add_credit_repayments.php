<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->uuid('request_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('payment_method', 16)->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('actor_name_snapshot', 120)->nullable();
            $table->string('customer_name_snapshot', 120)->nullable();
            $table->string('customer_phone_snapshot', 30)->nullable();
            $table->unique(['store_id', 'request_key']);
            $table->index(['store_id', 'entry_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['store_id', 'request_key']);
            $table->dropIndex(['store_id', 'entry_type', 'created_at']);
            $table->dropColumn(['request_key', 'request_hash', 'payment_method', 'notes', 'actor_name_snapshot', 'customer_name_snapshot', 'customer_phone_snapshot']);
        });
    }
};
