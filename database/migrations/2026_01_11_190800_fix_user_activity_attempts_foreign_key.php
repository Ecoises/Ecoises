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
        // Intentar eliminar la clave foránea anterior si existe.
        try {
            Schema::table('user_activity_attempts', function (Blueprint $table) {
                $table->dropForeign(['activity_id']);
            });
        } catch (\Exception $e) {
            // Si no existe la foránea, continuamos.
        }

        Schema::table('user_activity_attempts', function (Blueprint $table) {
            // Asegurarnos que la columna existe
            if (!Schema::hasColumn('user_activity_attempts', 'activity_id')) {
                 $table->foreignId('activity_id')->after('user_id'); // Crear columna
                 // Nota: foreignId crea unsignedBigInteger
            }

            // Agregar la nueva relación correcta hacia 'activities'
            // Verificar primero si no existe ya la foreign key para evitar duplicados si corremos esto varias veces?
            // Laravel migration suele fallar si FK ya existe con el mismo nombre.
            // Pero acabamos de intentar hacer drop.
            
            $table->foreign('activity_id')
                  ->references('id')
                  ->on('activities')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_activity_attempts', function (Blueprint $table) {
            // Eliminamos la relación correcta
            $table->dropForeign(['activity_id']);

            // Nota: No podemos restaurar fácilmente la relación incorrecta ('lesson_activities') 
            // porque esa tabla probablemente ya no existe.
        });
    }
};
