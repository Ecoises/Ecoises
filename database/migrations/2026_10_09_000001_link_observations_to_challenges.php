<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los avistamientos dejan de ser una red social tipo iNaturalist y pasan a
 * ser la evidencia de los retos de campo: "observa esta especie y gana puntos".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table): void {
            if (! Schema::hasColumn('challenges', 'taxon_id')) {
                $table->foreignId('taxon_id')->nullable()->after('challenge_type')
                    ->constrained('taxa')->nullOnDelete();
            }
            if (! Schema::hasColumn('challenges', 'requires_photo')) {
                $table->boolean('requires_photo')->default(true)->after('target_value');
            }
            $table->index(['is_active', 'start_date', 'end_date'], 'challenges_active_window_index');
        });

        Schema::table('observations', function (Blueprint $table): void {
            if (! Schema::hasColumn('observations', 'challenge_id')) {
                $table->foreignId('challenge_id')->nullable()->after('taxon_id')
                    ->constrained('challenges')->nullOnDelete();
            }
            if (! Schema::hasColumn('observations', 'verification_status')) {
                // null = avistamiento libre; pending/approved/rejected = evidencia de un reto
                $table->string('verification_status', 20)->nullable()->after('challenge_id');
                $table->foreignId('reviewed_by')->nullable()->after('verification_status')
                    ->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
                $table->text('review_note')->nullable()->after('reviewed_at');
            }
            $table->index(['challenge_id', 'user_id', 'verification_status'], 'observations_challenge_progress_index');
            $table->index(['is_public', 'observed_at'], 'observations_public_feed_index');
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table): void {
            $table->dropIndex('observations_public_feed_index');
            $table->dropIndex('observations_challenge_progress_index');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('challenge_id');
            $table->dropColumn(['verification_status', 'reviewed_at', 'review_note']);
        });

        Schema::table('challenges', function (Blueprint $table): void {
            $table->dropIndex('challenges_active_window_index');
            $table->dropConstrainedForeignId('taxon_id');
            $table->dropColumn('requires_photo');
        });
    }
};
