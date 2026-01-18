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
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('modular');
            $table->text('thumbnail_url')->nullable();
            $table->enum('difficulty_level', ['principiante', 'intermedio', 'avanzado'])->default('principiante');
            $table->string('category')->nullable();
            $table->integer('estimated_duration')->nullable();
            $table->integer('completion_points')->default(100);
            $table->foreignId('achievement_id')->nullable()->constrained('achievements')->onDelete('set null');
            $table->foreignId('author_id')->nullable()->constrained('users')->onDelete('set null');
            $table->boolean('is_published')->default(false);
            $table->integer('enrollment_count')->default(0);
            $table->decimal('completion_rate', 5, 2)->default(0.0);
            $table->decimal('rating_average', 3, 2)->default(0.0);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
