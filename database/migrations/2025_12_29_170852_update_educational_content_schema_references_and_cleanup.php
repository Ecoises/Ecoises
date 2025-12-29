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
        Schema::table('lessons', function (Blueprint $table) {
            $table->json('references')->nullable();
        });

        Schema::table('article_details', function (Blueprint $table) {
            $table->json('references')->nullable();
        });

        Schema::table('course_details', function (Blueprint $table) {
            $table->dropColumn(['related_taxa', 'target_location_ids']);
        });

        Schema::table('educational_content', function (Blueprint $table) {
             if (Schema::hasColumn('educational_content', 'references')) {
                $table->dropColumn('references');
             }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('references');
        });

        Schema::table('article_details', function (Blueprint $table) {
            $table->dropColumn('references');
        });

        Schema::table('course_details', function (Blueprint $table) {
            $table->json('related_taxa')->nullable();
            $table->json('target_location_ids')->nullable();
        });

        Schema::table('educational_content', function (Blueprint $table) {
            $table->json('references')->nullable();
        });
    }
};
