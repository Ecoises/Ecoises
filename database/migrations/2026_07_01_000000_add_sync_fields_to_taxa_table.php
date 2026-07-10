<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Agrega campos de sincronización para el pipeline de enriquecimiento:
     * - last_synced_at: Cuándo fue la última sincronización exitosa
     * - sync_status: Estado actual ('pending', 'syncing', 'synced', 'failed')
     * - sync_attempts: Conteo de intentos de sincronización
     * - gbif_taxon_key: ID de la especie en GBIF
     * - inat_taxon_id: ID de la especie en iNaturalist
     */
    public function up(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->timestamp('last_synced_at')
                  ->nullable()
                  ->after('updated_at')
                  ->comment('Última sincronización exitosa con APIs externas');
            
            $table->enum('sync_status', ['pending', 'syncing', 'synced', 'failed'])
                  ->default('pending')
                  ->after('last_synced_at')
                  ->comment('Estado actual del enriquecimiento');
            
            $table->unsignedTinyInteger('sync_attempts')
                  ->default(0)
                  ->after('sync_status')
                  ->comment('Número de intentos de sincronización');
            
            $table->string('gbif_taxon_key')
                  ->nullable()
                  ->after('sync_attempts')
                  ->index()
                  ->comment('ID único de la especie en GBIF');
            
            $table->string('inat_taxon_id')
                  ->nullable()
                  ->after('gbif_taxon_key')
                  ->index()
                  ->comment('ID único de la especie en iNaturalist');
            
            // Índice compuesto para queries eficientes del scheduler
            $table->index(['sync_status', 'last_synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->dropIndex(['sync_status', 'last_synced_at']);
            $table->dropColumn([
                'last_synced_at',
                'sync_status',
                'sync_attempts',
                'gbif_taxon_key',
                'inat_taxon_id',
            ]);
        });
    }
};
