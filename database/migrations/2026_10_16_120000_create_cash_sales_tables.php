<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('sold_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('sale_number', 40)->nullable();
            $table->uuid('idempotency_key');
            $table->string('request_hash', 64);
            $table->string('status', 16)->default('completed');
            $table->string('store_name_snapshot');
            $table->string('store_address_snapshot')->nullable();
            $table->string('store_phone_snapshot', 30)->nullable();
            $table->string('sold_by_name_snapshot');
            $table->unsignedInteger('subtotal_centavos');
            $table->unsignedInteger('discount_centavos')->default(0);
            $table->unsignedInteger('total_centavos');
            $table->unsignedInteger('amount_paid_centavos');
            $table->unsignedSmallInteger('items_count');
            $table->unsignedBigInteger('quantity_total');
            $table->dateTime('completed_at');
            $table->timestamps();
            $table->unique(['store_id', 'id']);
            $table->unique(['store_id', 'sale_number']);
            $table->unique(['store_id', 'idempotency_key']);
            $table->index(['store_id', 'completed_at', 'id']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sale_id');
            $table->unsignedBigInteger('product_id');
            $table->string('product_name_snapshot', 120);
            $table->string('sku_snapshot', 64)->nullable();
            $table->string('barcode_snapshot', 64)->nullable();
            $table->string('unit_snapshot', 16);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('unit_price_centavos');
            $table->unsignedInteger('unit_cost_centavos');
            $table->unsignedInteger('line_total_centavos');
            $table->unique(['sale_id', 'product_id']);
            $table->foreign(['store_id', 'sale_id'])->references(['store_id', 'id'])->on('sales')->restrictOnDelete();
            $table->foreign(['store_id', 'product_id'])->references(['store_id', 'id'])->on('products')->restrictOnDelete();
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('sale_id')->unique();
            $table->string('method', 20);
            $table->unsignedInteger('amount_centavos');
            $table->unsignedInteger('tendered_centavos');
            $table->unsignedInteger('change_centavos');
            $table->dateTime('created_at');
            $table->foreign(['store_id', 'sale_id'])->references(['store_id', 'id'])->on('sales')->restrictOnDelete();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->foreign(['store_id', 'sale_id'])->references(['store_id', 'id'])->on('sales')->restrictOnDelete();
            $table->unique(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['store_id', 'sale_id']);
            $table->dropUnique(['sale_id', 'product_id']);
            $table->dropColumn('sale_id');
        });
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
