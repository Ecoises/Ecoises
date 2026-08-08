<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->string('conservation_status_source', 50)->nullable()->after('conservation_status');
            $table->string('conservation_status_scope', 20)->nullable()->after('conservation_status_source');
            $table->string('conservation_status_authority')->nullable()->after('conservation_status_scope');
            $table->text('conservation_status_url')->nullable()->after('conservation_status_authority');
            $table->timestamp('conservation_status_synced_at')->nullable()->after('conservation_status_url');
        });
    }

    public function down(): void
    {
        Schema::table('taxa', function (Blueprint $table) {
            $table->dropColumn([
                'conservation_status_source',
                'conservation_status_scope',
                'conservation_status_authority',
                'conservation_status_url',
                'conservation_status_synced_at',
            ]);
        });
    }
};
