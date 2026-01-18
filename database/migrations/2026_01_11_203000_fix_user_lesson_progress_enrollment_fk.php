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
        // Intentar eliminar la clave foránea anterior incorrecta
        try {
            Schema::table('user_lesson_progress', function (Blueprint $table) {
                 $table->dropForeign(['enrollment_id']);
            });
        } catch (\Exception $e) {
            // Si falla (no existe), ignorar
        }

        Schema::table('user_lesson_progress', function (Blueprint $table) {
            // Agregar la nueva relación correcta hacia 'user_content_enrollments'
            // Verificar si la FK ya existe es complejo, pero dropForeign arriba intentó limpiarla.
            // Si la columna existe, agregamos la FK.
             if (Schema::hasColumn('user_lesson_progress', 'enrollment_id')) {
                $table->foreign('enrollment_id')
                      ->references('id')
                      ->on('user_content_enrollments')
                      ->onDelete('cascade');
             }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_lesson_progress', function (Blueprint $table) {
             $table->dropForeign(['enrollment_id']);
             // No restauramos la incorrecta
        });
    }
};
