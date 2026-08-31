<?php

use App\Models\Observation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->foreignId('observation_id')->nullable()->change();
            $table->nullableMorphs('reportable');
            $table->string('type', 40)->default('observation_report')->index();
            $table->string('category', 60)->nullable()->index();
            $table->string('subject')->nullable();
            $table->string('status', 30)->default('pending')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('metadata')->nullable();
        });

        DB::table('reports')
            ->whereNotNull('observation_id')
            ->update([
                'reportable_type' => Observation::class,
                'reportable_id' => DB::raw('observation_id'),
                'type' => 'observation_report',
            ]);
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropForeign(['assigned_to']);
            $table->dropForeign(['resolved_by']);
            $table->dropMorphs('reportable');
            $table->dropColumn([
                'type',
                'category',
                'subject',
                'status',
                'priority',
                'assigned_to',
                'resolved_by',
                'first_reviewed_at',
                'resolved_at',
                'resolution_notes',
                'metadata',
            ]);
        });
    }
};
