<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Services\AnnouncementPublicationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AnnouncementVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
            $table->string('avatar')->nullable();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary')->nullable();
            $table->longText('body')->nullable();
            $table->string('cover_image')->nullable();
            $table->string('cta_label')->nullable();
            $table->text('cta_url')->nullable();
            $table->string('audience')->default('all');
            $table->string('status')->default('draft');
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();
        });
    }

    public function test_public_api_only_returns_active_public_announcements(): void
    {
        $this->announcement('visible', [
            'status' => Announcement::STATUS_PUBLISHED,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);
        $this->announcement('draft');
        $this->announcement('future', [
            'status' => Announcement::STATUS_PUBLISHED,
            'starts_at' => now()->addHour(),
        ]);
        $this->announcement('expired', [
            'status' => Announcement::STATUS_PUBLISHED,
            'ends_at' => now()->subMinute(),
        ]);
        $this->announcement('private', [
            'status' => Announcement::STATUS_PUBLISHED,
            'audience' => 'authenticated',
        ]);

        $this->getJson('/api/announcements?limit=12')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.slug', 'visible');

        $this->getJson('/api/announcements/visible')
            ->assertOk()
            ->assertJsonPath('slug', 'visible');

        $this->getJson('/api/announcements/private')->assertNotFound();
    }

    public function test_an_announcement_is_validated_before_publication(): void
    {
        $draft = $this->announcement('incompleto');

        try {
            app(AnnouncementPublicationService::class)->publish($draft);
            $this->fail('Un anuncio vacío no debe publicarse.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $draft->update([
            'title' => 'Jornada de observación de aves',
            'summary' => 'Participa en una salida educativa para reconocer aves locales.',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addDay(),
        ]);

        $published = app(AnnouncementPublicationService::class)->publish($draft->fresh());

        $this->assertSame(Announcement::STATUS_PUBLISHED, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertFalse(Announcement::visible()->whereKey($published)->exists());
    }

    private function announcement(string $slug, array $overrides = []): Announcement
    {
        return Announcement::create(array_merge([
            'title' => ucfirst($slug),
            'slug' => $slug,
            'status' => Announcement::STATUS_DRAFT,
            'audience' => 'all',
        ], $overrides));
    }
}
