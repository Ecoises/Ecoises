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
        Schema::create('taxa', function (Blueprint $table) {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->string('common_name')->nullable();
            $table->string('kingdom', 100)->nullable();
            $table->string('phylum', 100)->nullable();
            $table->string('class', 100)->nullable();
            $table->string('order_name', 100)->nullable();
            $table->string('family', 100)->nullable();
            $table->string('genus', 100)->nullable();
            $table->string('species', 100)->nullable();
            $table->enum('conservation_status', ['LC', 'NT', 'VU', 'EN', 'CR', 'EW', 'EX', 'DD', 'NE'])->nullable();
            $table->boolean('is_native')->nullable();
            $table->boolean('is_endemic')->nullable();
            $table->integer('observation_count')->default(0);
            $table->integer('identification_count')->default(0);
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamps();

            $table->index('scientific_name');
            $table->index('common_name');
            $table->index('observation_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('taxa');
    }
};
