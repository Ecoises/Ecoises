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
            $table->text('audio_url')->nullable()->after('media_type');
            $table->json('audio_timestamps')->nullable()->after('audio_url');
            $table->string('voice_id')->nullable()->after('audio_timestamps');
        });

        Schema::table('courses', function (Blueprint $table) {
             $table->json('references')->nullable()->after('target_location_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['audio_url', 'audio_timestamps', 'voice_id']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('references');
        });
    }
};
