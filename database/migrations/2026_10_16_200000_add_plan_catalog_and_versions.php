<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->string('catalog_status', 20)->default('draft')->index();
            $table->string('quota_scope', 16)->default('store');
            $table->json('planned_features')->nullable();
            $table->boolean('featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedInteger('version')->default(0);
        });
        Schema::create('plan_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('terms');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('change_note', 255)->nullable();
            $table->timestamp('created_at');
            $table->unique(['plan_id', 'version']);
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('plan_version_id')->nullable()->constrained('plan_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropConstrainedForeignId('plan_version_id'));
        Schema::dropIfExists('plan_versions');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn([
            'description', 'catalog_status', 'quota_scope', 'planned_features', 'featured', 'sort_order', 'version',
        ]));
    }
};
