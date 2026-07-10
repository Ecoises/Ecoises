<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->boolean('is_introduced')
                  ->nullable()
                  ->after('is_endemic')
                  ->comment('Indica si la especie es introducida en Colombia');
        });
    }

    public function down(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->dropColumn('is_introduced');
        });
    }
};
