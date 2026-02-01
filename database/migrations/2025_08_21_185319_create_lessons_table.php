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
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->onDelete('cascade');
            $table->string('title');
            $table->string('slug')->unique();
            $table->integer('lesson_order');
            $table->text('content_text')->nullable();
            $table->text('audio_url')->nullable();
            $table->json('audio_timestamps')->nullable();
            $table->string('voice_id')->nullable();
            $table->text('media_url')->nullable();
            $table->string('media_type')->nullable();
            $table->integer('estimated_duration')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->json('unlock_requirements')->nullable();
            $table->boolean('is_published')->default(false);
            $table->integer('view_count')->default(0);
            $table->decimal('completion_rate', 5, 2)->default(0.0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
