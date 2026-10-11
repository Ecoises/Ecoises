<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto de importancia ecológica de cada especie, redactado con IA a partir de
 * datos verificados (GBIF, GloBI, EOL, Wikipedia) y revisable desde el panel.
 * Uno por especie: se genera una vez y se sirve desde aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('species_ecology_texts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxon_id')->unique()->constrained('taxa')->cascadeOnDelete();
            $table->text('text');
            $table->json('sources')->nullable();          // fuentes de los hechos usados
            $table->string('model', 80)->nullable();      // modelo que lo redactó
            $table->string('status', 20)->default('published'); // published · reviewed · rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('species_ecology_texts');
    }
};
