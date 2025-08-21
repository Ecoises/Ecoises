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
        Schema::create('observation_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('observation_id')->constrained('observations')->onDelete('cascade');
            $table->text('photo_url');
            $table->boolean('is_primary')->default(false);
            $table->text('caption')->nullable();
            $table->integer('photo_order')->default(1);
            $table->integer('file_size')->nullable();
            $table->integer('image_width')->nullable();
            $table->integer('image_height')->nullable();
            $table->timestamps();

            $table->index('observation_id');
            $table->index(['observation_id', 'is_primary']);
        });

        Schema::table('observation_photos', function (Blueprint $table) {
            $table->unique(['observation_id', 'is_primary'], 'idx_one_primary_photo_per_observation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observation_photos');
    }
};
