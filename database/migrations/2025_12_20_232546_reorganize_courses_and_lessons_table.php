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
        Schema::table('courses', function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending', 'reviewed', 'published'])->default('draft')->after('is_published');
            $table->longText('content_text')->nullable()->after('description');
            $table->string('voice_id')->nullable()->after('content_text');
            $table->string('audio_url', 2048)->nullable()->after('voice_id');
            $table->json('audio_timestamps')->nullable()->after('audio_url');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending', 'reviewed', 'published'])->default('draft')->after('is_published');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['status', 'content_text', 'voice_id', 'audio_url', 'audio_timestamps']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
