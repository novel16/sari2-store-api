<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_documents', function (Blueprint $table) {
            $table->id();
            $table->json('draft');
            $table->json('published');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('published_version')->default(1);
            $table->timestamp('published_at');
            $table->timestamps();
        });
        Schema::create('website_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 30);
            $table->json('content');
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot', 120)->nullable();
            $table->string('change_note', 255);
            $table->timestamps();
            $table->unique(['website_document_id', 'version']);
        });
        $content = file_get_contents(database_path('data/website.json'));
        DB::table('website_documents')->insert(['id' => 1, 'draft' => $content, 'published' => $content,
            'version' => 1, 'published_version' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('website_revisions')->insert(['website_document_id' => 1, 'version' => 1, 'action' => 'initialized',
            'content' => $content, 'change_note' => 'Initial website content', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('website_revisions');
        Schema::dropIfExists('website_documents');
    }
};
