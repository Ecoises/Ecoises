<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'transaction_type', 'reference_type', 'reference_id'],
                'unique_point_award_source',
            );
        });

        Schema::table('user_lesson_progress', function (Blueprint $table): void {
            $table->unique(['user_id', 'lesson_id'], 'unique_user_lesson_progress');
        });

        Schema::table('user_activity_attempts', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'activity_id', 'attempt_number'],
                'unique_user_activity_attempt_number',
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_activity_attempts', function (Blueprint $table): void {
            $table->dropUnique('unique_user_activity_attempt_number');
        });

        Schema::table('user_lesson_progress', function (Blueprint $table): void {
            $table->dropUnique('unique_user_lesson_progress');
        });

        Schema::table('point_transactions', function (Blueprint $table): void {
            $table->dropUnique('unique_point_award_source');
        });
    }
};
