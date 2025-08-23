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
        Schema::table('observations', function (Blueprint $table) {
            $table->foreignId('location_id')
                  ->nullable()
                  ->after('taxon_id')
                  ->constrained()
                  ->onDelete('set null');
            
            // Índice para optimizar consultas
            $table->index('location_id', 'idx_observations_location_id');
            
            // Índice compuesto para búsquedas geográficas
            $table->index(['location_id', 'latitude', 'longitude'], 'idx_observations_geo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex('idx_observations_location_id');
            $table->dropIndex('idx_observations_geo');
            $table->dropColumn('location_id');
        });
    }
};