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
        Schema::create('user_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('lesson_id')->constrained('lessons')->onDelete('cascade');
            // enrollment_id will be handled/linked properly in later migrations (Refactor)
            // We define it as nullable bigInt for now so data isn't lost if filled, 
            // but without constraint until the target table exists.
            $table->unsignedBigInteger('enrollment_id')->nullable();
            
            $table->string('status')->default('no_iniciada'); // pending/en_progreso/completada
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            
            // Adding stats columns we recently discussed, initializing them here
            $table->integer('activities_completed')->default(0);
            $table->integer('total_activities')->default(0);
            $table->integer('points_earned')->default(0);
            $table->integer('points_possible')->default(0);
            $table->integer('time_spent')->default(0);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_lesson_progress');
    }
};
