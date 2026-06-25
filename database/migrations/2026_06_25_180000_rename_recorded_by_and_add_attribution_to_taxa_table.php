<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Renombra 'recorded_by' → 'taxon_author' (autor que describió la especie)
     * y agrega 'attribution' para citar la fuente del registro (artículo, plataforma, etc.)
     */
    public function up(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            // Renombrar el campo
            $table->renameColumn('recorded_by', 'taxon_author');

            // Nuevo campo de atribución/citación de fuente
            $table->text('attribution')
                  ->nullable()
                  ->after('local_records_count')
                  ->comment('Cita bibliográfica o fuente de los datos (artículo, plataforma, base de datos)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->renameColumn('taxon_author', 'recorded_by');
            $table->dropColumn('attribution');
        });
    }
};
