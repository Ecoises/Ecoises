<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            // Trazabilidad local del inventario del sitio
            $table->string('recorded_by', 255)->nullable()->after('is_endemic')
                  ->comment('Nombre del autor del inventario: líder de semillero, integrante o cualquier persona que documentó la especie');
            $table->string('inventory_author', 255)->nullable()->after('recorded_by')
                  ->comment('Semillero, grupo o colectivo responsable del registro (ej: Ornitología, Herpetología)');
            $table->unsignedInteger('local_records_count')->default(0)->after('inventory_author')
                  ->comment('Número total de registros documentados en el sitio (escalable: campus, reserva, parque, etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->dropColumn(['recorded_by', 'inventory_author', 'local_records_count']);
        });
    }
};
