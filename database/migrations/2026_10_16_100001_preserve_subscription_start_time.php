<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legacy MySQL can silently give the first TIMESTAMP an ON UPDATE clause.
        // A plan update must not move the subscription's start date into the future.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dateTime('starts_at')->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Explicit default avoids restoring environment-dependent ON UPDATE behavior.
            $table->timestamp('starts_at')->useCurrent()->change();
        });
    }
};
