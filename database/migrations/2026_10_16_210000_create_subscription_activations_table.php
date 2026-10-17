<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name_snapshot');
            $table->string('action', 16);
            $table->string('settlement_method', 24);
            $table->unsignedTinyInteger('months');
            $table->unsignedBigInteger('amount_centavos');
            $table->string('payment_reference', 100)->nullable()->unique();
            $table->string('reason', 500);
            $table->json('snapshot');
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->char('quote_hash', 64)->unique();
            $table->timestamp('created_at');
            $table->index(['account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_activations');
    }
};
