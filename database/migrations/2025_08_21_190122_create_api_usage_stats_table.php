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
        Schema::create('api_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->string('api_source')->nullable();
            $table->string('endpoint')->nullable();
            $table->integer('request_count')->default(1);
            $table->date('date');
            $table->timestamps();
            
            $table->unique(['api_source', 'endpoint', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_usage_stats');
    }
};
