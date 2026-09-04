<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('educational_content_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('educational_content')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('change_summary')->nullable();
            $table->char('snapshot_hash', 64);
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['content_id', 'version_number']);
            $table->index(['content_id', 'event', 'created_at'], 'educational_versions_timeline_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('educational_content_versions');
    }
};
