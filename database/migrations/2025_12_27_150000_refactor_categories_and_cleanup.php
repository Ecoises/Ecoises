<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create content_categories
        if (!Schema::hasTable('content_categories')) {
            Schema::create('content_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->timestamps();
            });

            // 2. Migrate Data from course_categories -> content_categories
            if (Schema::hasTable('course_categories')) {
                DB::statement("
                    INSERT INTO content_categories (id, name, slug, description, created_at, updated_at)
                    SELECT id, name, slug, description, created_at, updated_at
                    FROM course_categories
                ");
            }
        }

        // 3. Create Pivot Table
        if (!Schema::hasTable('category_content')) {
            Schema::create('category_content', function (Blueprint $table) {
                $table->foreignId('category_id')->constrained('content_categories')->onDelete('cascade');
                $table->foreignId('content_id')->constrained('educational_content')->onDelete('cascade');
                $table->primary(['category_id', 'content_id']);
            });

            // 4. Migrate Relations (educational_content.category_id -> pivot)
            // Only if category_id column exists
            if (Schema::hasColumn('educational_content', 'category_id')) {
                DB::statement("
                    INSERT INTO category_content (category_id, content_id)
                    SELECT category_id, id
                    FROM educational_content
                    WHERE category_id IS NOT NULL
                ");
            }
        }

        // 5. Drop category_id from educational_content
        if (Schema::hasColumn('educational_content', 'category_id')) {
            Schema::table('educational_content', function (Blueprint $table) {
                try {
                    $table->dropForeign(['category_id']);
                } catch (\Exception $e) {}
                
                $table->dropColumn('category_id');
            });
        }

        // 6. Drop dependent foreign keys and tables
        Schema::dropIfExists('user_course_enrollments');
        
        // Also check if lessons still references courses (it shouldn't if Step 1 rename worked, but maybe old FK persists?)
        // In previous migration we renamed course_id -> content_id.
        // But if there are other tables referencing courses?
        // Let's force check disable checks if needed, but better to be explicit.
        
        // Let's force check disable checks if needed, but better to be explicit.
        try {
            Schema::table('lessons', function (Blueprint $table) {
                 // Just in case old FK name persists differently
                 // This might fail if FK doesn't exist, hence the outer try-catch
                 $table->dropForeign(['course_id']);
            });
        } catch (\Throwable $e) {}

        // 7. Drop old tables
        Schema::dropIfExists('courses'); 
        Schema::dropIfExists('course_categories');
    }

    public function down(): void
    {
        // Not easily reversible without data loss (dropping tables)
        // But we can try to recreate logic.
        Schema::dropIfExists('category_content');
        Schema::dropIfExists('content_categories');
        
        // Re-add category_id to educational_content?
        Schema::table('educational_content', function (Blueprint $table) {
             $table->foreignId('category_id')->nullable();
        });
        
        // Cannot restore courses/course_categories data unless from backup.
    }
};
