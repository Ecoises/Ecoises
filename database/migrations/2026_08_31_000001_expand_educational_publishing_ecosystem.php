<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('educational_content_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('educational_content')->cascadeOnDelete();
            $table->string('asset_type', 30);
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('file_path')->nullable();
            $table->text('external_url')->nullable();
            $table->boolean('is_downloadable')->default(true);
            $table->unsignedInteger('asset_order')->default(0);
            $table->timestamps();

            $table->index(['content_id', 'asset_order']);
            $table->index('asset_type');
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('cta_label')->nullable();
            $table->text('cta_url')->nullable();
            $table->string('audience', 30)->default('all');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['is_pinned', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('educational_content_assets');
    }
};
