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
        Schema::create('user_resource_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('resource_id')->constrained('educational_resources')->onDelete('cascade');
            $table->timestamp('first_viewed_at')->useCurrent();
            $table->timestamp('last_viewed_at')->useCurrent();
            $table->integer('view_count')->default(1);
            $table->integer('time_spent')->default(0);
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->boolean('is_bookmarked')->default(false);
            $table->boolean('is_liked')->default(false);
            $table->boolean('is_downloaded')->default(false);
            $table->decimal('progress_percentage', 5, 2)->default(0.0);
            $table->string('last_position', 50)->nullable();
            $table->timestamps();
            
            $table->unique(['user_id', 'resource_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_resource_interactions');
    }
};
