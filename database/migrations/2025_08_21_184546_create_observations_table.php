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
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('taxon_id')->nullable()->constrained('taxa')->onDelete('set null');
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->integer('location_accuracy')->nullable();
            $table->text('location_description')->nullable();
            $table->timestamp('observed_at');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->string('identification_status') ;
            $table->enum('confidence_level', ['baja', 'media', 'alta'])->nullable();
            $table->decimal('quality_score', 3, 2)->default(0.0);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_research_grade')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index('taxon_id');
            $table->index(['latitude', 'longitude']);
            $table->index('observed_at');
            $table->index('identification_status');

            
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
