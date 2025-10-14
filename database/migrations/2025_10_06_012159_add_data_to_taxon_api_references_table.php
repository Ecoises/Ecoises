<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('taxon_api_references', function (Blueprint $table) {
            $table->json('data')->nullable()->comment('Datos completos de la API externa')->after('api_url');
        });
       
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('taxon_api_references', function (Blueprint $table) {
            $table->dropColumn('data');
        });
       
    }
};
