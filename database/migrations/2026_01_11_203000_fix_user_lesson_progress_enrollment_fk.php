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
        Schema::table('user_lesson_progress', function (Blueprint $table) {
            // Intentar eliminar la clave foránea anterior incorrecta
            // El nombre por convención sería user_lesson_progress_enrollment_id_foreign
            try {
                $table->dropForeign(['enrollment_id']);
            } catch (\Exception $e) {
                // Si falla (no existe), ignorar
            }

            // Agregar la nueva relación correcta hacia 'user_content_enrollments'
            $table->foreign('enrollment_id')
                  ->references('id')
                  ->on('user_content_enrollments')
                  ->onDelete('cascade');
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
