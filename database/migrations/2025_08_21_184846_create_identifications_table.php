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
        Schema::create('identifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('taxon_id')->constrained('taxa')->onDelete('cascade');
            $table->enum('confidence', ['baja', 'media', 'alta'])->default('media');
            $table->text('reasoning')->nullable();
            $table->boolean('is_automatic')->default(false);
            $table->decimal('ai_confidence', 5, 4)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'observation_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identifications');
    }
};
