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
        Schema::create('achievements', function (Blueprint $table) {
            $table->id();
             $table->string('name', 100);
            $table->text('description')->nullable();
            $table->text('icon_url')->nullable();
            $table->string('category', 50); 
            $table->integer('points')->default(0);
            $table->string('requirement_type');
            $table->json('requirement_criteria')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('rarity', 50); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('achievements');
    }
};
