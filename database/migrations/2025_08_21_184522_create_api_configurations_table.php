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
        Schema::create('api_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('api_source')->unique();
            $table->text('base_url');
            $table->boolean('api_key_required')->default(false);
            $table->integer('rate_limit_requests')->nullable();
            $table->integer('rate_limit_period')->nullable();
            $table->integer('daily_limit')->nullable();
            $table->integer('monthly_limit')->nullable();
            $table->integer('cache_ttl_description')->default(604800);
            $table->integer('cache_ttl_images')->default(86400);
            $table->integer('cache_ttl_sounds')->default(86400);
            $table->integer('cache_ttl_distribution')->default(2592000);
            $table->integer('cache_ttl_conservation')->default(2592000);
            $table->integer('cache_ttl_characteristics')->default(604800);
            $table->integer('cache_ttl_references')->default(2592000);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_health_check')->nullable();
            $table->enum('health_status', ['healthy', 'degraded', 'unavailable'])->default('healthy');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_configurations');
    }
};
