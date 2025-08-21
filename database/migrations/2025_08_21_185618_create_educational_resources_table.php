<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('educational_resources', function (Blueprint $table) {
            $table->id();
            $table->string('resource_type')->nullable();
            $table->text('content_text')->nullable();
            $table->text('media_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->enum('difficulty_level', ['principiante', 'intermedio', 'avanzado'])->default('principiante');
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->integer('estimated_duration')->nullable();
            $table->json('related_taxa')->nullable();
            $table->json('related_courses')->nullable();
            $table->json('prerequisite_courses')->nullable();
            $table->foreignId('author_id')->nullable()->constrained('users')->onDelete('set null');
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('view_count')->default(0);
            $table->integer('like_count')->default(0);
            $table->integer('download_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('educational_resources');
    }
};
