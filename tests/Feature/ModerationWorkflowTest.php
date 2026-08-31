<?php

namespace Tests\Feature;

use App\Models\EducationalContent;
use App\Models\Observation;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModerationWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('educational_content', function (Blueprint $table): void {
            $table->id();
            $table->string('content_type')->nullable();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
        });

        Schema::create('user_content_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('content_id');
            $table->timestamp('enrolled_at')->useCurrent();
            $table->timestamp('last_accessed_at')->nullable();
            $table->integer('user_rating')->nullable();
            $table->text('user_feedback')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'content_id']);
        });

        Schema::create('observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->unsignedBigInteger('taxon_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });

        Schema::create('reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('observation_id')->nullable();
            $table->nullableMorphs('reportable');
            $table->string('type')->default(Report::TYPE_OBSERVATION);
            $table->string('category')->nullable();
            $table->string('subject')->nullable();
            $table->text('comment');
            $table->string('status')->default(Report::STATUS_PENDING);
            $table->string('priority')->default(Report::PRIORITY_NORMAL);
            $table->foreignId('assigned_to')->nullable();
            $table->foreignId('resolved_by')->nullable();
            $table->timestamp('first_reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function test_general_feedback_enters_the_moderation_queue(): void
    {
        Sanctum::actingAs($this->user('persona@ecois.es'));

        $this->postJson('/api/feedback', [
            'subject' => 'Mejora de accesibilidad',
            'category' => 'accessibility',
            'comment' => 'Sería útil aumentar el contraste de algunos textos.',
            'context' => ['page' => '/educacion'],
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', Report::STATUS_PENDING);

        $this->assertDatabaseHas('reports', [
            'type' => Report::TYPE_GENERAL_FEEDBACK,
            'category' => 'accessibility',
            'status' => Report::STATUS_PENDING,
        ]);
    }

    public function test_content_rating_is_aggregated_and_feedback_is_not_duplicated(): void
    {
        $user = $this->user('estudiante@ecois.es');
        Sanctum::actingAs($user);
        $content = EducationalContent::create([
            'title' => 'Conservación local',
            'slug' => 'conservacion-local',
            'status' => EducationalContent::STATUS_PUBLISHED,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $payload = ['rating' => 4, 'comment' => 'El ejemplo fue muy claro.'];
        $this->postJson("/api/educational-contents/{$content->id}/feedback", $payload)->assertOk();
        $this->postJson("/api/educational-contents/{$content->id}/feedback", [
            'rating' => 5,
            'comment' => 'Ahora quedó aún mejor.',
        ])->assertOk();

        $this->assertSame(1, Report::where('type', Report::TYPE_CONTENT_FEEDBACK)->count());
        $this->assertDatabaseHas('user_content_enrollments', [
            'user_id' => $user->id,
            'content_id' => $content->id,
            'user_rating' => 5,
        ]);
        $this->assertSame('5.00', $content->fresh()->rating_average);
        $this->assertSame(1, $content->fresh()->rating_count);
    }

    public function test_the_same_observation_cannot_have_duplicate_open_reports_by_one_user(): void
    {
        $owner = $this->user('autor@ecois.es');
        $reporter = $this->user('reporta@ecois.es');
        $observation = Observation::create(['user_id' => $owner->id]);
        Sanctum::actingAs($reporter);

        $payload = ['comment' => 'La identificación parece incorrecta.', 'category' => 'incorrect_identification'];
        $this->postJson("/api/observations/{$observation->id}/report", $payload)->assertOk();
        $this->postJson("/api/observations/{$observation->id}/report", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame(1, Report::where('type', Report::TYPE_OBSERVATION)->count());
    }

    public function test_review_and_resolution_timestamps_are_recorded(): void
    {
        $report = Report::create([
            'user_id' => $this->user('caso@ecois.es')->id,
            'type' => Report::TYPE_GENERAL_FEEDBACK,
            'comment' => 'Mensaje de prueba',
        ]);

        $report->update(['status' => Report::STATUS_IN_REVIEW]);
        $this->assertNotNull($report->fresh()->first_reviewed_at);

        $report->update(['status' => Report::STATUS_RESOLVED]);
        $this->assertNotNull($report->fresh()->resolved_at);
    }

    private function user(string $email): User
    {
        return User::create([
            'full_name' => 'Persona de prueba',
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
