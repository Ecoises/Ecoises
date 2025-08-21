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
        Schema::create('taxon_api_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxon_id')->constrained('taxa')->onDelete('cascade');
            $table->string('api_source')->nullable();;
            $table->string('external_id', 100)->nullable();
            $table->text('api_url')->nullable();
            $table->decimal('confidence_score', 3, 2)->default(1.0);
            $table->boolean('is_primary')->default(false);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            
            $table->unique(['taxon_id', 'api_source', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxon_api_references');
    }
};
