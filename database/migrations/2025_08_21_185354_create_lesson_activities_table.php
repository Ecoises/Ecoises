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
        Schema::create('lesson_activities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->integer('activity_order');
            $table->enum('activity_type', ['quiz_multiple', 'quiz_true_false', 'drag_drop', 'matching', 'fill_blanks', 'image_hotspot', 'classification', 'sequencing', 'memory_game', 'word_search', 'crossword']);
            $table->text('instructions')->nullable();
            $table->json('content_data');
            $table->json('correct_answers')->nullable();
            $table->json('hints')->nullable();
            $table->integer('max_points')->default(10);
            $table->integer('passing_score')->default(7);
            $table->integer('time_limit')->nullable();
            $table->integer('attempts_allowed')->default(3);
            $table->text('success_message')->nullable();
            $table->text('failure_message')->nullable();
            $table->text('explanation')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_activities');
    }
};
