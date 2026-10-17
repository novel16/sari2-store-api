<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('sale_id')->unique();
            $table->foreign(['store_id', 'sale_id'])->references(['store_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name_snapshot', 120);
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('kind', 16);
            $table->string('reason', 255);
            $table->boolean('restock');
            $table->unsignedInteger('total_centavos');
            $table->unsignedInteger('cash_refund_centavos');
            $table->unsignedInteger('credit_reversed_centavos');
            $table->unsignedBigInteger('balance_before_centavos')->nullable();
            $table->unsignedBigInteger('balance_after_centavos')->nullable();
            $table->dateTime('created_at');
            $table->unique(['store_id', 'request_key']);
            $table->index(['store_id', 'created_at', 'id']);
        });
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->foreignId('sale_reversal_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('sale_reversal_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['sale_reversal_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropUnique(['sale_reversal_id', 'product_id']);
            $table->dropConstrainedForeignId('sale_reversal_id');
        });
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropUnique(['sale_reversal_id']);
            $table->dropConstrainedForeignId('sale_reversal_id');
        });
        Schema::dropIfExists('sale_reversals');
    }
};
