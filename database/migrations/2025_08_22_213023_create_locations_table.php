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
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Nombre de la ubicación');
            $table->decimal('latitude', 10, 8)->comment('Latitud de la ubicación');
            $table->decimal('longitude', 11, 8)->comment('Longitud de la ubicación');
            $table->decimal('radius_km', 8, 2)->comment('Radio en kilómetros para delimitar el área');
            $table->boolean('is_active')->default(true)->comment('Indica si la ubicación está activa');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};