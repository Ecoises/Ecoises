<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Activity;
use App\Models\EducationalContent;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\User;
use App\Services\ActivityEvaluationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityAttemptSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->integer('total_score')->default(0);
            $table->string('level')->nullable();
            $table->timestamps();
        });

        Schema::create('educational_content', function (Blueprint $table): void {
            $table->id();
            $table->string('content_type');
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->foreignId('author_id');
            $table->string('difficulty_level')->default('principiante');
            $table->integer('estimated_duration')->default(0);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->timestamps();
        });

        Schema::create('educational_content_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id');
            $table->string('asset_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('file_path')->nullable();
            $table->text('external_url')->nullable();
            $table->boolean('is_downloadable')->default(true);
            $table->unsignedInteger('asset_order')->default(0);
            $table->timestamps();
        });

        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id');
            $table->string('title');
            $table->string('slug')->unique();
            $table->integer('lesson_order');
            $table->text('content_text')->nullable();
            $table->integer('estimated_duration')->nullable();
            $table->integer('points')->default(10);
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_published')->default(false);
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        Schema::create('content_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });

        Schema::create('category_content', function (Blueprint $table): void {
            $table->foreignId('content_id');
            $table->foreignId('category_id');
        });

        Schema::create('course_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->integer('completion_points')->default(100);
            $table->unsignedBigInteger('achievement_id')->nullable();
        });

        Schema::create('article_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->longText('content_text');
        });

        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('activitable_id');
            $table->string('activitable_type');
            $table->string('title');
            $table->integer('activity_order')->default(1);
            $table->string('activity_type');
            $table->text('instructions')->nullable();
            $table->json('content_data');
            $table->json('correct_answers')->nullable();
            $table->json('hints')->nullable();
            $table->integer('max_points')->default(10);
            $table->integer('passing_score')->default(7);
            $table->integer('time_limit')->nullable();
            $table->integer('attempts_allowed')->default(3);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();
        });

        Schema::create('user_content_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('content_id');
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedBigInteger('current_lesson_id')->nullable();
            $table->integer('lessons_completed')->default(0);
            $table->integer('total_lessons')->default(0);
            $table->decimal('progress_percentage', 5, 2)->default(0);
            $table->integer('total_points_earned')->default(0);
            $table->integer('total_points_possible')->default(0);
            $table->decimal('final_score', 5, 2)->nullable();
            $table->integer('total_time_spent')->default(0);
            $table->integer('user_rating')->nullable();
            $table->text('user_feedback')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'content_id']);
        });

        Schema::create('user_lesson_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('lesson_id');
            $table->foreignId('enrollment_id');
            $table->string('status')->default('en_progreso');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->integer('activities_completed')->default(0);
            $table->integer('total_activities')->default(0);
            $table->integer('points_earned')->default(0);
            $table->integer('points_possible')->default(0);
            $table->integer('time_spent')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'lesson_id']);
        });

        Schema::create('user_article_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('article_id');
            $table->foreignId('enrollment_id');
            $table->string('status')->default('no_iniciada');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->decimal('reading_progress', 5, 2)->default(0);
            $table->integer('last_position')->nullable();
            $table->integer('activities_completed')->default(0);
            $table->integer('total_activities')->default(0);
            $table->integer('points_earned')->default(0);
            $table->integer('points_possible')->default(0);
            $table->integer('time_spent')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'article_id']);
        });

        Schema::create('user_activity_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('activity_id');
            $table->foreignId('lesson_progress_id');
            $table->integer('attempt_number');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('user_answers')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->integer('points_earned')->default(0);
            $table->integer('time_taken')->nullable();
            $table->json('hints_used')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'activity_id', 'attempt_number']);
        });

        Schema::create('point_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->integer('points');
            $table->string('transaction_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference_type')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'transaction_type', 'reference_type', 'reference_id']);
        });

        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('min_points')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('icon_url')->nullable();
            $table->string('category');
            $table->integer('points')->default(0);
            $table->string('requirement_type');
            $table->json('requirement_criteria')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('rarity');
            $table->timestamps();
        });

        Schema::create('user_achievements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('achievement_id');
            $table->timestamp('earned_at')->nullable();
            $table->json('progress_data')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'achievement_id']);
        });
    }

    public function test_the_public_payload_does_not_expose_the_correct_answer(): void
    {
        [, $activity] = $this->publishedActivity();

        $payload = app(ActivityEvaluationService::class)->publicPayload($activity);

        $this->assertArrayNotHasKey('content_data', $payload);
        $this->assertArrayNotHasKey('correct_answers', $payload);
        $this->assertArrayNotHasKey('is_correct', $payload['options'][0]);
        $this->assertArrayNotHasKey('feedback', $payload['options'][0]);
    }

    public function test_the_public_content_endpoint_uses_the_safe_activity_payload(): void
    {
        [, $activity, $lesson] = $this->publishedActivity();

        $response = $this->getJson("/api/educational-contents/{$lesson->content->slug}")
            ->assertOk()
            ->assertJsonPath('lessons.0.activities.0.id', $activity->id)
            ->assertJsonMissingPath('lessons.0.activities.0.content_data')
            ->assertJsonMissingPath('lessons.0.activities.0.correct_answers')
            ->assertJsonMissingPath('lessons.0.activities.0.options.0.is_correct');

        $this->assertNotEmpty($response->json('lessons.0.activities.0.options.0.id'));
    }

    public function test_the_client_cannot_forge_correctness_or_score(): void
    {
        [$user, $activity] = $this->publishedActivity();
        Sanctum::actingAs($user);
        $options = app(ActivityEvaluationService::class)->publicPayload($activity)['options'];

        $this->postJson("/api/activities/{$activity->id}/attempt", [
            'is_correct' => true,
            'score' => 999999,
            'answers' => ['option_id' => $options[1]['id']],
        ])
            ->assertOk()
            ->assertJsonPath('is_correct', false)
            ->assertJsonPath('points_awarded', 0)
            ->assertJsonPath('total_points_awarded', 0);

        $this->assertSame(0, $user->fresh()->total_score);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_points_are_awarded_only_once_for_a_correct_activity(): void
    {
        [$user, $activity] = $this->publishedActivity();
        Sanctum::actingAs($user);
        $option = app(ActivityEvaluationService::class)->publicPayload($activity)['options'][0];

        $this->postJson("/api/activities/{$activity->id}/attempt", [
            'answers' => ['option_id' => $option['id']],
        ])
            ->assertOk()
            ->assertJsonPath('is_correct', true)
            ->assertJsonPath('already_completed', false)
            ->assertJsonPath('points_awarded', 25)
            ->assertJsonPath('total_points_awarded', 25);

        $this->postJson("/api/activities/{$activity->id}/attempt", [
            'answers' => ['option_id' => $option['id']],
        ])
            ->assertOk()
            ->assertJsonPath('already_completed', true)
            ->assertJsonPath('points_awarded', 0);

        $this->assertSame(25, $user->fresh()->total_score);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertDatabaseCount('user_activity_attempts', 1);
    }

    public function test_an_earned_achievement_and_level_are_associated_with_the_user(): void
    {
        Level::create(['name' => 'Explorador', 'min_points' => 0, 'is_active' => true]);
        $observer = Level::create(['name' => 'Observador', 'min_points' => 30, 'is_active' => true]);
        Achievement::create([
            'name' => 'Primer desafío',
            'category' => 'aprendizaje',
            'points' => 10,
            'requirement_type' => 'activities_completed',
            'requirement_criteria' => ['count' => 1],
            'is_active' => true,
            'rarity' => 'común',
        ]);
        [$user, $activity] = $this->publishedActivity();
        Sanctum::actingAs($user);
        $option = app(ActivityEvaluationService::class)->publicPayload($activity)['options'][0];

        $this->postJson("/api/activities/{$activity->id}/attempt", [
            'answers' => ['option_id' => $option['id']],
        ])
            ->assertOk()
            ->assertJsonPath('achievements.0.name', 'Primer desafío')
            ->assertJsonPath('total_points_awarded', 35);

        $this->assertSame(35, $user->fresh()->total_score);
        $this->assertSame($observer->id, (int) $user->fresh()->level);
        $this->assertDatabaseCount('user_achievements', 1);
        $this->assertDatabaseCount('point_transactions', 2);
    }

    public function test_an_activity_from_an_unpublished_lesson_cannot_be_attempted(): void
    {
        [$user, $activity, $lesson] = $this->publishedActivity();
        $lesson->update(['status' => 'draft', 'is_published' => false]);
        Sanctum::actingAs($user);

        $this->postJson("/api/activities/{$activity->id}/attempt", [
            'answers' => ['option_id' => 'forged'],
        ])->assertNotFound();
    }

    public function test_course_completion_reconciles_progress_and_all_rewards_once(): void
    {
        $achievement = Achievement::create([
            'name' => 'Guardián del curso',
            'category' => 'conservación',
            'points' => 20,
            'requirement_type' => 'manual',
            'requirement_criteria' => [],
            'is_active' => true,
            'rarity' => 'épico',
        ]);
        [$user, $activity, $lesson] = $this->publishedActivity();
        $lesson->content->courseDetails()->create([
            'completion_points' => 100,
            'achievement_id' => $achievement->id,
        ]);
        Sanctum::actingAs($user);
        $option = app(ActivityEvaluationService::class)->publicPayload($activity)['options'][0];

        $this->postJson("/api/activities/{$activity->id}/attempt", [
            'answers' => ['option_id' => $option['id']],
        ])->assertOk();
        $this->postJson("/api/lessons/{$lesson->slug}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completada');
        $this->postJson("/api/lessons/{$lesson->slug}/complete")->assertOk();

        $this->assertSame(155, $user->fresh()->total_score);
        $this->assertDatabaseCount('point_transactions', 4);
        $this->assertDatabaseCount('user_achievements', 1);
        $this->assertDatabaseHas('user_content_enrollments', [
            'user_id' => $user->id,
            'content_id' => $lesson->content_id,
            'progress_percentage' => 100,
            'total_points_earned' => 35,
            'total_points_possible' => 35,
            'final_score' => 100,
        ]);
    }

    public function test_article_progress_is_persistent_monotonic_and_completes_its_enrollment(): void
    {
        $user = $this->user();
        $article = EducationalContent::create([
            'content_type' => EducationalContent::TYPE_ARTICLE,
            'title' => 'Artículo de humedales',
            'slug' => 'articulo-humedales',
            'description' => 'Lectura educativa',
            'author_id' => $user->id,
            'status' => EducationalContent::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/educational-contents/{$article->slug}/start")->assertOk();
        $this->patchJson("/api/educational-contents/{$article->slug}/article-progress", [
            'reading_progress' => 60,
            'time_spent' => 30,
        ])->assertOk()->assertJsonPath('reading_progress', '60.00');
        $this->patchJson("/api/educational-contents/{$article->slug}/article-progress", [
            'reading_progress' => 20,
        ])->assertOk()->assertJsonPath('reading_progress', '60.00');
        $this->patchJson("/api/educational-contents/{$article->slug}/article-progress", [
            'reading_progress' => 100,
        ])->assertOk()->assertJsonPath('status', 'completada');

        $this->assertDatabaseHas('user_content_enrollments', [
            'user_id' => $user->id,
            'content_id' => $article->id,
            'progress_percentage' => 100,
        ]);
    }

    public function test_learning_dashboard_summarizes_progress_without_exposing_answers(): void
    {
        $user = $this->user();
        Level::create(['name' => 'Explorador', 'min_points' => 0, 'is_active' => true]);
        Level::create(['name' => 'Naturalista', 'min_points' => 100, 'is_active' => true]);
        $user->update(['total_score' => 35]);
        $content = EducationalContent::create([
            'content_type' => EducationalContent::TYPE_ARTICLE,
            'title' => 'Bosques secos',
            'slug' => 'bosques-secos-dashboard',
            'description' => 'Aprendizaje sobre el bosque seco tropical.',
            'author_id' => $user->id,
            'status' => EducationalContent::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $this->postJson("/api/educational-contents/{$content->slug}/start")->assertOk();

        $this->getJson('/api/learning/dashboard')
            ->assertOk()
            ->assertJsonPath('learner.total_points', 35)
            ->assertJsonPath('learner.next_level.name', 'Naturalista')
            ->assertJsonPath('learner.next_level.points_remaining', 65)
            ->assertJsonPath('stats.enrolled', 1)
            ->assertJsonPath('stats.in_progress', 1)
            ->assertJsonPath('continue_learning.0.content.slug', 'bosques-secos-dashboard')
            ->assertJsonMissingPath('continue_learning.0.correct_answers');
    }

    /** @return array{User, Activity, Lesson} */
    private function publishedActivity(): array
    {
        $user = $this->user();
        $content = EducationalContent::create([
            'content_type' => EducationalContent::TYPE_COURSE,
            'title' => 'Curso seguro',
            'slug' => 'curso-seguro-'.fake()->unique()->numerify('####'),
            'description' => 'Contenido de prueba',
            'author_id' => $user->id,
            'status' => EducationalContent::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now(),
        ]);
        $lesson = $content->lessons()->create([
            'title' => 'Lección segura',
            'slug' => 'leccion-segura-'.fake()->unique()->numerify('####'),
            'lesson_order' => 1,
            'content_text' => 'Contenido',
            'status' => EducationalContent::STATUS_PUBLISHED,
            'is_published' => true,
        ]);
        $activity = $lesson->activities()->create([
            'title' => '¿Cuál opción es correcta?',
            'activity_order' => 1,
            'activity_type' => 'quiz_multiple',
            'content_data' => [
                'options' => [
                    ['text' => 'La opción correcta', 'is_correct' => true, 'feedback' => 'Muy bien'],
                    ['text' => 'La opción incorrecta', 'is_correct' => false, 'feedback' => 'Revisa la lección'],
                ],
            ],
            'max_points' => 25,
            'attempts_allowed' => 3,
            'is_mandatory' => true,
        ]);

        return [$user, $activity, $lesson];
    }

    private function user(): User
    {
        return User::create([
            'full_name' => 'Estudiante de prueba',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
        ]);
    }
}
