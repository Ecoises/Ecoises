<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Listas oficiales de Colombia descargadas de GBIF (sin token):
 *  - threat_co: Resolución 0126 de 2024 de MinAmbiente (CR/EN/VU)
 *  - endemic_birds_co: aves endémicas y casi endémicas (Instituto Humboldt)
 *  - introduced_co: GRIIS Colombia (especies introducidas e invasoras)
 * Y la categoría global de la Lista Roja de la UICN por especie (vía GBIF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colombia_species_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('list', 40);
            $table->string('gbif_taxon_key', 20)->nullable()->index();
            $table->string('scientific_name')->index();
            $table->string('category', 30)->nullable(); // CR/EN/VU · endemic · near_endemic · introduced
            $table->timestamps();

            $table->unique(['list', 'scientific_name']);
        });

        Schema::table('taxa', function (Blueprint $table): void {
            $table->string('iucn_category', 2)->nullable()->after('conservation_status_synced_at');
            $table->string('iucn_taxon_id', 20)->nullable()->after('iucn_category');
            $table->timestamp('iucn_synced_at')->nullable()->after('iucn_taxon_id');
        });
    }

    public function down(): void
    {
        Schema::table('taxa', function (Blueprint $table): void {
            $table->dropColumn(['iucn_category', 'iucn_taxon_id', 'iucn_synced_at']);
        });

        Schema::dropIfExists('colombia_species_lists');
    }
};
