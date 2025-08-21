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
        Schema::create('unified_api_caches', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key')->unique();
            $table->enum('cache_type', ['taxon_data', 'general_query', 'search_results']);
            $table->foreignId('taxon_id')->nullable()->constrained('taxa')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('api_source')->nullable();
            $table->enum('data_type', ['descripción', 'imágenes', 'sonidos', 'distribución', 'conservación', 'características', 'referencias', 'taxonomía', 'búsqueda', 'otros']);
            $table->text('request_url')->nullable();
            $table->json('request_params')->nullable();
            $table->json('response_data');
            $table->json('response_metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_accessed_at')->useCurrent();
            $table->integer('hit_count')->default(1);
            $table->timestamps();
            
            $table->index(['taxon_id', 'api_source', 'data_type']);
            $table->index('expires_at');
            $table->index('last_accessed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unified_api_caches');
    }
};
