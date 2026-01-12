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
        Schema::table('user_activity_attempts', function (Blueprint $table) {
            // Intentar eliminar la clave foránea anterior si existe.
            // Usamos un bloque try/catch implícito al verificar si existe el índice (Laravel a veces maneja esto, pero seremos explícitos con la sintaxis de array que busca el nombre convencional)
            
            try {
                $table->dropForeign(['activity_id']);
            } catch (\Exception $e) {
                // Si no existe la foránea, continuamos.
            }

            // Asegurarnos que la columna use el tipo correcto (unsignedBigInteger) si no lo era
            // $table->unsignedBigInteger('activity_id')->change(); 

            // Agregar la nueva relación correcta hacia 'activities'
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
