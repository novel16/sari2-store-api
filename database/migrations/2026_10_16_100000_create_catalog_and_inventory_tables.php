<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 60);
            $table->string('name_key', 180);
            $table->string('description', 120)->nullable();
            $table->string('emoji', 16)->default('🛍️');
            $table->string('color', 7)->default('#e9efe2');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['store_id', 'name_key']);
            $table->unique(['store_id', 'id']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('unit', 16)->default('piece');
            $table->unsignedInteger('price_centavos');
            $table->unsignedInteger('cost_centavos');
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->string('status', 16)->default('active');
            $table->string('emoji', 16)->default('🛍️');
            $table->string('color', 7)->default('#e9efe2');
            $table->timestamps();
            $table->unique(['store_id', 'id']);
            $table->unique(['store_id', 'sku']);
            $table->unique(['store_id', 'barcode']);
            $table->index(['store_id', 'status']);
            $table->foreign(['store_id', 'category_id'])->references(['store_id', 'id'])->on('categories')->restrictOnDelete();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id')->unique();
            $table->unsignedInteger('quantity_on_hand')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['store_id', 'product_id']);
            $table->foreign(['store_id', 'product_id'])->references(['store_id', 'id'])->on('products')->restrictOnDelete();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('movement_type', 20);
            $table->integer('quantity_delta');
            $table->unsignedInteger('quantity_before');
            $table->unsignedInteger('quantity_after');
            $table->unsignedInteger('version_after');
            $table->string('reason', 255);
            $table->uuid('request_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['store_id', 'product_id'])->references(['store_id', 'id'])->on('products')->restrictOnDelete();
            $table->unique(['store_id', 'request_key']);
            $table->index(['store_id', 'created_at', 'id']);
            $table->index(['store_id', 'product_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
