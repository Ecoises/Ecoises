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
        Schema::create('user_course_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('course_id')->constrained('courses')->onDelete('cascade');
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->foreignId('current_lesson_id')->nullable()->constrained('lessons')->onDelete('set null');
            $table->integer('lessons_completed')->default(0);
            $table->integer('total_lessons')->default(0);
            $table->decimal('progress_percentage', 5, 2)->default(0.0);
            $table->integer('total_points_earned')->default(0);
            $table->integer('total_points_possible')->default(0);
            $table->decimal('final_score', 5, 2)->nullable();
            $table->integer('total_time_spent')->default(0);
            $table->integer('user_rating')->nullable();
            $table->text('user_feedback')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_course_enrollments');
    }
};
