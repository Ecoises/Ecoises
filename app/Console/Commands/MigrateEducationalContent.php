<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MigrateEducationalContent extends Command
{
    protected $signature = 'db:migrate-educational-content';

    protected $description = 'Migrate data to new CTI structure based on Revised Plan';

    public function handle()
    {
        $this->info('Starting REVISED Data Migration (PHP Logic)...');

        DB::transaction(function () {
            // STEP 1: Migrate COURSES
            $this->info('Migrating from courses to educational_content...');

            $usedSlugs = [];

            DB::table('courses')->orderBy('id')->chunk(100, function ($courses) use (&$usedSlugs) {
                foreach ($courses as $course) {
                    $courseData = (array) $course;

                    // Handle missing 'slug'
                    $baseSlug = $courseData['slug'] ?? Str::slug($courseData['title'] ?? 'untitled-'.$course->id);
                    $slug = $baseSlug;
                    $counter = 1;

                    // Simple in-memory duplications check + DB check (if re-running or existing data)
                    // Since we are inside transaction and single run, in-memory is enough if table was empty.
                    while (in_array($slug, $usedSlugs)) {
                        $slug = $baseSlug.'-'.$counter;
                        $counter++;
                    }
                    $usedSlugs[] = $slug;

                    // Handle missing 'type'
                    // Default to 'course' as per findings
                    $contentType = 'course';
                    if (isset($courseData['type'])) {
                        $contentType = match ($courseData['type']) {
                            'simple' => 'article',
                            'modular' => 'course',
                            default => 'course',
                        };
                    }

                    // Educational Content
                    DB::table('educational_content')->insert([
                        'id' => $course->id,
                        'content_type' => $contentType,
                        'title' => $course->title,
                        'slug' => $slug,
                        'description' => $course->description ?? null,
                        'thumbnail_url' => $course->thumbnail_url ?? null,
                        'author_id' => $course->author_id,
                        'category_id' => $course->category_id ?? null,
                        'tags' => $course->tags ?? null,
                        'difficulty_level' => $course->difficulty_level ?? 'principiante',
                        'estimated_duration' => $course->estimated_duration ?? 0,
                        'is_published' => $course->is_published ?? 0,
                        'is_featured' => $course->is_featured ?? 0,
                        'status' => $course->status ?? 'draft',
                        'references' => $course->references ?? null,
                        'view_count' => $course->view_count ?? 0,
                        'rating_average' => $course->rating_average ?? 0.00,
                        'rating_count' => $course->rating_count ?? 0,
                        'created_at' => $course->created_at,
                        'updated_at' => $course->updated_at,
                    ]);

                    // Details
                    if ($contentType === 'course') {
                        DB::table('course_details')->insert([
                            'id' => $course->id,
                            'completion_points' => $course->completion_points ?? 100,
                            'achievement_id' => $course->achievement_id ?? null,
                            'related_taxa' => $course->related_taxa ?? null, // Assuming column might exist, else null
                            'target_location_ids' => $course->target_location_ids ?? null,
                            'enrollment_count' => $course->enrollment_count ?? 0,
                            'completion_rate' => $course->completion_rate ?? 0.00,
                            'has_certificate' => $course->has_certificate ?? 0,
                        ]);
                    } else {
                        // Article details
                        DB::table('article_details')->insert([
                            'id' => $course->id,
                            'content_text' => $course->description ?? '', // Fallback
                            'audio_url' => $course->audio_url ?? null,
                            'audio_timestamps' => $course->audio_timestamps ?? null,
                            'voice_id' => $course->voice_id ?? null,
                            'read_time' => $course->estimated_duration ?? 0,
                            'word_count' => 0,
                        ]);
                    }
                }
            });

            // STEP 3: ACTIVITIES
            // Assuming lesson_activities exists and has columns.
            $this->info('Migrating activities...');
            if (Schema::hasTable('lesson_activities')) {
                DB::table('lesson_activities')->orderBy('id')->chunk(100, function ($activities) {
                    foreach ($activities as $activity) {
                        $actData = (array) $activity;
                        DB::table('activities')->insert([
                            'activitable_id' => $actData['lesson_id'],
                            'activitable_type' => 'App\Models\Lesson',
                            'title' => $actData['title'],
                            'activity_order' => $actData['order'] ?? $actData['activity_order'] ?? 0,
                            'activity_type' => $actData['activity_type'],
                            'content_data' => $actData['content_data'],
                            'correct_answers' => $actData['correct_answers'] ?? null,
                            'hints' => $actData['hints'] ?? null,
                            'max_points' => $actData['points'] ?? 10,
                            'passing_score' => 7,
                            'attempts_allowed' => 3,
                            'is_mandatory' => $actData['is_mandatory'] ?? 1,
                            'created_at' => $actData['created_at'],
                            'updated_at' => $actData['updated_at'],
                        ]);
                    }
                });
            }

            // STEP 5: ENROLLMENTS
            $this->info('Migrating enrollments...');
            if (Schema::hasTable('user_course_enrollments')) {
                DB::table('user_course_enrollments')->orderBy('id')->chunk(100, function ($enrollments) {
                    foreach ($enrollments as $enrollment) {
                        $enr = (array) $enrollment;
                        DB::table('user_content_enrollments')->insert([
                            'id' => $enr['id'],
                            'user_id' => $enr['user_id'],
                            'content_id' => $enr['course_id'],
                            'enrolled_at' => $enr['enrolled_at'],
                            'started_at' => $enr['started_at'] ?? null,
                            'completed_at' => $enr['completed_at'] ?? null,
                            'last_accessed_at' => $enr['last_accessed_at'] ?? null,
                            'current_lesson_id' => $enr['current_lesson_id'] ?? null,
                            'lessons_completed' => $enr['lessons_completed'] ?? 0,
                            'total_lessons' => $enr['total_lessons'] ?? 0,
                            'progress_percentage' => $enr['progress_percentage'] ?? 0,
                            'total_points_earned' => $enr['total_points_earned'] ?? 0,
                            'user_rating' => $enr['user_rating'] ?? null,
                            'user_feedback' => $enr['user_feedback'] ?? null,
                            'created_at' => $enr['created_at'],
                            'updated_at' => $enr['updated_at'],
                        ]);
                    }
                });
            }

        });

        $this->info('REVISED PHP Migration completed successfully!');
    }
}
