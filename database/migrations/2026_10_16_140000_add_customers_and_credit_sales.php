<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('phone', 30)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('balance_centavos')->default(0);
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->timestamps();
            $table->unique(['store_id', 'id']);
            $table->unique(['store_id', 'request_key']);
            $table->index(['store_id', 'status', 'name']);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->string('payment_method', 16)->default('cash');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('customer_name_snapshot', 120)->nullable();
            $table->string('customer_phone_snapshot', 30)->nullable();
            $table->unsignedInteger('credit_amount_centavos')->default(0);
            $table->foreign(['store_id', 'customer_id'])->references(['store_id', 'id'])->on('customers')->restrictOnDelete();
        });
        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('sale_id')->nullable()->unique();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('entry_type', 20);
            $table->unsignedInteger('amount_centavos');
            $table->unsignedBigInteger('balance_before_centavos');
            $table->unsignedBigInteger('balance_after_centavos');
            $table->dateTime('created_at');
            $table->foreign(['store_id', 'customer_id'])->references(['store_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['store_id', 'sale_id'])->references(['store_id', 'id'])->on('sales')->restrictOnDelete();
            $table->index(['store_id', 'customer_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['store_id', 'customer_id']);
            $table->dropColumn(['payment_method', 'customer_id', 'customer_name_snapshot', 'customer_phone_snapshot', 'credit_amount_centavos']);
        });
        Schema::dropIfExists('customers');
    }
};
