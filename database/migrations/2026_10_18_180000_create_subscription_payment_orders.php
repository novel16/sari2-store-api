<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            // One unresolved checkout holds this unique slot. A delayed receipt
            // remains payable evidence without replacing a newer checkout's slot.
            $table->foreignId('active_account_id')->nullable()->unique()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('owner_name_snapshot');
            $table->string('account_name_snapshot');
            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('plan_version');
            $table->json('terms');
            $table->unsignedTinyInteger('months');
            $table->unsignedBigInteger('amount_centavos');
            $table->char('currency', 3)->default('PHP');
            $table->boolean('livemode');
            $table->string('payment_status', 16)->default('creating');
            $table->string('fulfillment_status', 24)->default('pending');
            $table->string('checkout_session_id', 100)->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->string('payment_id', 100)->nullable()->unique();
            $table->foreignId('subscription_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->string('issue_code', 80)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index(['account_id', 'id']);
            $table->index(['payment_status', 'expires_at']);
            $table->index(['fulfillment_status', 'payment_status']);
        });

        Schema::create('paymongo_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('event_type', 100);
            $table->boolean('livemode');
            $table->string('checkout_session_id', 100)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('subscription_orders')->restrictOnDelete();
            $table->longText('payload');
            $table->char('payload_hash', 64);
            $table->string('status', 16)->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymongo_webhook_events');
        Schema::dropIfExists('subscription_orders');
    }
};
