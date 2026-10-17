<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });

        Schema::create('account_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['account_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['account_id', 'slug']);
            $table->index(['account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
        Schema::dropIfExists('account_memberships');
        Schema::dropIfExists('accounts');
    }
};
