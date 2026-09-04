<?php

namespace Tests\Feature;

use App\Models\EducationalContent;
use App\Models\User;
use App\Services\EducationalContentPublicationService;
use App\Services\EducationalDraftService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EducationalContentCoreTest extends TestCase
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
            $table->string('thumbnail_url')->nullable();
            $table->foreignId('author_id');
            $table->json('tags')->nullable();
            $table->string('difficulty_level')->default('principiante');
            $table->integer('estimated_duration')->default(0);
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('view_count')->default(0);
            $table->decimal('rating_average', 3, 2)->default(0);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
        });

        Schema::create('course_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->integer('completion_points')->default(100);
            $table->unsignedBigInteger('achievement_id')->nullable();
            $table->integer('enrollment_count')->default(0);
            $table->decimal('completion_rate', 5, 2)->default(0);
            $table->boolean('has_certificate')->default(false);
            $table->json('prerequisite_content_ids')->nullable();
        });

        Schema::create('article_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->longText('content_text');
            $table->string('audio_url')->nullable();
            $table->json('audio_timestamps')->nullable();
            $table->string('voice_id')->nullable();
            $table->integer('read_time')->default(0);
            $table->integer('word_count')->default(0);
            $table->json('related_taxa')->nullable();
            $table->json('references')->nullable();
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

        Schema::create('content_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });

        Schema::create('category_content', function (Blueprint $table): void {
            $table->foreignId('category_id');
            $table->foreignId('content_id');
        });

        Schema::create('activities', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('activitable');
            $table->string('title');
            $table->unsignedInteger('activity_order')->default(0);
            $table->string('activity_type');
            $table->json('content_data')->nullable();
            $table->json('correct_answers')->nullable();
            $table->timestamps();
        });

        Schema::create('educational_content_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id');
            $table->unsignedInteger('version_number');
            $table->foreignId('created_by')->nullable();
            $table->string('event');
            $table->string('change_summary')->nullable();
            $table->char('snapshot_hash', 64);
            $table->json('snapshot');
            $table->timestamps();
            $table->unique(['content_id', 'version_number']);
        });
    }

    public function test_a_new_draft_has_no_premature_subtype_structure(): void
    {
        $draft = app(EducationalDraftService::class)->create($this->author());

        $this->assertNull($draft->content_type);
        $this->assertFalse($draft->courseDetails()->exists());
        $this->assertFalse($draft->articleDetails()->exists());
        $this->assertSame(0, $draft->lessons()->count());
        $this->assertSame(EducationalContent::STATUS_DRAFT, $draft->status);
    }

    public function test_the_content_type_is_assigned_once_and_cannot_be_mixed(): void
    {
        $service = app(EducationalDraftService::class);
        $draft = $service->create($this->author());
        $course = $service->assignType($draft, EducationalContent::TYPE_COURSE);

        $this->assertSame(EducationalContent::TYPE_COURSE, $course->content_type);
        $this->assertTrue($course->courseDetails()->exists());
        $this->assertFalse($course->articleDetails()->exists());

        $this->expectException(ValidationException::class);
        $service->assignType($course, EducationalContent::TYPE_ARTICLE);
    }

    public function test_a_draft_is_not_exposed_by_the_public_api(): void
    {
        $draft = app(EducationalDraftService::class)->create($this->author());

        $this->getJson("/api/educational-contents/{$draft->slug}")
            ->assertNotFound();

        $this->getJson('/api/educational-contents')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_a_complete_article_can_be_published_atomically(): void
    {
        $drafts = app(EducationalDraftService::class);
        $article = $drafts->create($this->author());
        $article = $drafts->autosave($article, [
            'content_type' => EducationalContent::TYPE_ARTICLE,
            'title' => 'Polinizadores urbanos',
            'slug' => 'polinizadores-urbanos',
            'description' => 'Una introducción a su importancia ecológica.',
            'article_details' => [
                'content_text' => '<p>Las abejas y otros organismos sostienen la reproducción de muchas plantas.</p>',
            ],
        ]);

        $published = app(EducationalContentPublicationService::class)->publish($article);

        $this->assertSame(EducationalContent::STATUS_PUBLISHED, $published->status);
        $this->assertTrue($published->is_published);
        $this->assertNotNull($published->published_at);
        $this->assertTrue($published->isPublished());
        $this->assertTrue(EducationalContent::published()->whereKey($published)->exists());
    }

    public function test_publishing_a_course_also_publishes_its_lessons(): void
    {
        $drafts = app(EducationalDraftService::class);
        $course = $drafts->create($this->author());
        $course = $drafts->autosave($course, [
            'content_type' => EducationalContent::TYPE_COURSE,
            'title' => 'Conservación de humedales',
            'slug' => 'conservacion-humedales',
            'description' => 'Curso sobre el cuidado comunitario de los humedales.',
        ]);
        $lesson = $course->lessons()->create([
            'title' => '¿Por qué importan los humedales?',
            'slug' => 'importancia-humedales',
            'lesson_order' => 1,
            'content_text' => '<p>Regulan el agua y brindan refugio a numerosas especies.</p>',
        ]);

        $published = app(EducationalContentPublicationService::class)->publish($course->fresh());

        $this->assertTrue($published->isPublished());
        $this->assertSame(EducationalContent::STATUS_PUBLISHED, $lesson->fresh()->status);
        $this->assertTrue($lesson->fresh()->is_published);
    }

    public function test_an_incomplete_course_cannot_be_published(): void
    {
        $drafts = app(EducationalDraftService::class);
        $course = $drafts->create($this->author());
        $course = $drafts->autosave($course, [
            'content_type' => EducationalContent::TYPE_COURSE,
            'title' => 'Conservación local',
            'slug' => 'conservacion-local',
            'description' => 'Curso introductorio sobre conservación comunitaria.',
        ]);

        $this->expectException(ValidationException::class);
        app(EducationalContentPublicationService::class)->publish($course);
    }

    public function test_a_multimedia_resource_uses_the_same_draft_and_publication_flow(): void
    {
        $drafts = app(EducationalDraftService::class);
        $resource = $drafts->create($this->author());
        $resource = $drafts->autosave($resource, [
            'content_type' => EducationalContent::TYPE_RESOURCE,
            'title' => 'Guía de polinizadores',
            'slug' => 'guia-polinizadores',
            'description' => 'Una infografía descargable para reconocer polinizadores locales.',
        ]);

        $this->assertFalse($resource->articleDetails()->exists());
        $this->assertFalse($resource->courseDetails()->exists());

        try {
            app(EducationalContentPublicationService::class)->publish($resource);
            $this->fail('Un recurso vacío no debe poder publicarse.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $resource->assets()->create([
            'asset_type' => 'infographic',
            'title' => 'Polinizadores del Caribe colombiano',
            'file_path' => 'content/resources/polinizadores.png',
            'asset_order' => 1,
        ]);

        $published = app(EducationalContentPublicationService::class)->publish($resource->fresh());

        $this->assertTrue($published->isPublished());
        $this->getJson('/api/educational-contents/guia-polinizadores')
            ->assertOk()
            ->assertJsonPath('content_type', EducationalContent::TYPE_RESOURCE)
            ->assertJsonPath('assets.0.asset_type', 'infographic');
    }

    public function test_content_moves_through_review_before_editorial_publication(): void
    {
        $drafts = app(EducationalDraftService::class);
        $article = $drafts->create($this->author());
        $article = $drafts->autosave($article, [
            'content_type' => EducationalContent::TYPE_ARTICLE,
            'title' => 'Restauración ecológica comunitaria',
            'slug' => 'restauracion-ecologica-comunitaria',
            'description' => 'Principios básicos para recuperar ecosistemas locales.',
            'article_details' => [
                'content_text' => '<p>La restauración combina conocimiento ecológico y participación comunitaria.</p>',
            ],
        ]);

        $workflow = app(EducationalContentPublicationService::class);
        $pending = $workflow->submitForReview($article);
        $pendingStatus = $pending->status;
        $reviewed = $workflow->markReviewed($pending);
        $reviewedStatus = $reviewed->status;
        $published = $workflow->publish($reviewed);

        $this->assertSame(EducationalContent::STATUS_PENDING, $pendingStatus);
        $this->assertSame(EducationalContent::STATUS_REVIEWED, $reviewedStatus);
        $this->assertSame(EducationalContent::STATUS_PUBLISHED, $published->status);
        $this->assertTrue($published->isPublished());
        $this->assertSame([1, 2, 3], $published->versions()->reorder('version_number')->pluck('version_number')->all());
        $this->assertSame(
            ['submitted', 'reviewed', 'published'],
            $published->versions()->reorder('version_number')->pluck('event')->all(),
        );
        $this->assertSame(
            'Restauración ecológica comunitaria',
            $published->versions()->orderByDesc('version_number')->first()->snapshot['content']['title'],
        );
    }

    private function author(): User
    {
        return User::create([
            'full_name' => 'Educadora de prueba',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
        ]);
    }
}
