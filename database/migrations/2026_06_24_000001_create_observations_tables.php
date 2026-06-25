<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->unsignedBigInteger('taxon_id')->nullable();
            $table->foreign('taxon_id')
                  ->references('id')->on('taxa')
                  ->nullOnDelete();

            // Ubicación embebida directamente (sin tabla locations)
            $table->decimal('latitude', 10, 8)->nullable()
                  ->comment('Latitud del avistamiento en grados decimales');
            $table->decimal('longitude', 11, 8)->nullable()
                  ->comment('Longitud del avistamiento en grados decimales');
            $table->string('location_name', 255)->nullable()
                  ->comment('Nombre descriptivo del lugar (ej: Jardín Botánico UNAL La Paz)');

            // Datos del avistamiento
            $table->timestamp('observed_at')->nullable()
                  ->comment('Fecha y hora en que se realizó el avistamiento');
            $table->text('description')->nullable();
            $table->text('notes')->nullable();

            // Visibilidad y gamificación
            $table->boolean('is_public')->default(true);
            $table->integer('points_awarded')->default(0)
                  ->comment('Puntos de gamificación otorgados al usuario por este registro');

            $table->timestamps();
        });

        Schema::create('observation_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('observation_id')
                  ->constrained('observations')
                  ->cascadeOnDelete();

            $table->text('photo_url');
            $table->boolean('is_primary')->default(false);
            $table->text('caption')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_photos');
        Schema::dropIfExists('observations');
    }
};
