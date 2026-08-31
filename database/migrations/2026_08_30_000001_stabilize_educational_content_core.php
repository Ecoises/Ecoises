<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('educational_content', function (Blueprint $table) {
            $table->enum('content_type', ['course', 'article'])->nullable()->change();
            $table->timestamp('published_at')->nullable()->after('status')->index();
        });

        DB::table('educational_content')
            ->where('status', 'published')
            ->whereNull('published_at')
            ->update(['published_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);

        DB::table('educational_content')
            ->where('status', 'published')
            ->update(['is_published' => true]);

        DB::table('educational_content')
            ->where('status', '!=', 'published')
            ->update(['is_published' => false, 'published_at' => null]);

        DB::table('lessons')
            ->whereIn('content_id', DB::table('educational_content')
                ->select('id')
                ->where('status', 'published'))
            ->update(['status' => 'published', 'is_published' => true]);

        DB::table('lessons')
            ->whereIn('content_id', DB::table('educational_content')
                ->select('id')
                ->where('status', '!=', 'published'))
            ->update(['status' => 'draft', 'is_published' => false]);
    }

    public function down(): void
    {
        DB::table('educational_content')
            ->whereNull('content_type')
            ->update(['content_type' => 'course']);

        Schema::table('educational_content', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropColumn('published_at');
            $table->enum('content_type', ['course', 'article'])->nullable(false)->change();
        });
    }
};
