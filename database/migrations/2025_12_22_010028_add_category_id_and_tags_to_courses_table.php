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
            $table->foreignId('category_id')->nullable()->constrained('course_categories')->onDelete('set null')->after('difficulty_level');
            $table->json('tags')->nullable()->after('category_id');
        });

        // Migrate existing category data
        $courses = DB::table('courses')->get();
        foreach ($courses as $course) {
            if ($course->category) {
                $category = DB::table('course_categories')->where('slug', $course->category)->first();
                if (!$category) {
                    $id = DB::table('course_categories')->insertGetId([
                        'name' => ucfirst($course->category),
                        'slug' => $course->category,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $id = $category->id;
                }
                DB::table('courses')->where('id', $course->id)->update(['category_id' => $id]);
            }
        }

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('category')->nullable()->after('difficulty_level');
        });

        // Optional: migrate back if needed, but usually not necessary for reversions
        
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn(['category_id', 'tags']);
        });
    }
};
