<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 16)->default('open');
            $table->unsignedInteger('opening_cash_centavos');
            $table->string('notes', 500)->nullable();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('opened_by_name_snapshot');
            $table->dateTime('opened_at');
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('closed_by_name_snapshot')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->json('closing')->nullable();
            $table->uuid('closing_request_key')->nullable();
            $table->char('closing_request_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'id']);
            $table->unique(['store_id', 'business_date']);
            $table->unique(['store_id', 'request_key']);
            $table->unique(['store_id', 'closing_request_key']);
            $table->index(['store_id', 'business_date', 'id']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('cash_day_id');
            $table->foreign(['store_id', 'cash_day_id'])->references(['store_id', 'id'])->on('cash_days')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name_snapshot');
            $table->string('direction', 3);
            $table->unsignedInteger('amount_centavos');
            $table->string('reason', 255);
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->dateTime('created_at');
            $table->unique(['store_id', 'request_key']);
            $table->index(['store_id', 'cash_day_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_days');
    }
};
