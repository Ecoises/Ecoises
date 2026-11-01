<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Challenge;
use App\Models\Level;
use App\Models\Observation;
use App\Models\User;
use App\Services\Gamification\ChallengeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
            $table->text('avatar')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->integer('total_score')->default(0);
            $table->string('level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('achievements', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('points')->default(0);
            $table->string('requirement_type');
            $table->json('requirement_criteria')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('challenges', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('challenge_type')->nullable();
            $table->foreignId('taxon_id')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->integer('target_value')->nullable();
            $table->boolean('requires_photo')->default(false);
            $table->integer('reward_points')->default(0);
            $table->foreignId('reward_badge_id')->nullable();
            $table->json('criteria')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('participant_count')->default(0);
            $table->timestamps();
        });

        Schema::create('challenge_participations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('challenge_id');
            $table->integer('progress_value')->default(0);
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
        });

        Schema::create('observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id');
            $table->foreignId('taxon_id')->nullable();
            $table->foreignId('challenge_id')->nullable();
            $table->string('verification_status')->nullable();
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
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
        });

        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('min_points')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
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

    public function test_completing_a_challenge_notifies_the_reward_and_the_level_up(): void
    {
        Level::create(['name' => 'Semilla', 'min_points' => 0]);
        Level::create(['name' => 'Explorador', 'min_points' => 100]);
        $user = $this->user('ana@example.com');
        $challenge = Challenge::create(['title' => 'Aves de humedal', 'reward_points' => 150, 'target_value' => 1]);

        $this->evidence($user, $challenge);

        $types = $user->notifications()->pluck('type')->sort()->values()->all();
        $this->assertSame(['eco.challenge.completed', 'eco.progress.level_up'], $types);
    }

    public function test_notifications_are_broadcast_on_the_users_private_channel(): void
    {
        Event::fake([BroadcastNotificationCreated::class]);
        $user = $this->user('ana@example.com');
        $challenge = Challenge::create(['title' => 'Aves de humedal', 'reward_points' => 0, 'target_value' => 1]);

        $this->evidence($user, $challenge);

        Event::assertDispatched(BroadcastNotificationCreated::class, function (BroadcastNotificationCreated $event) use ($user) {
            $payload = $event->broadcastWith();

            return $event->broadcastOn()[0]->name === "private-App.Models.User.{$user->id}"
                && $payload['type'] === 'challenge.completed'
                && $payload['category'] === 'challenge'
                && $payload['id'] === $event->notification->id
                && isset($payload['title'], $payload['created_at']);
        });
    }

    public function test_the_private_channel_is_only_authorized_for_its_owner(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');

        $ana = $this->user('ana@example.com');
        $luis = $this->user('luis@example.com');
        $request = fn (User $owner) => ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$owner->id}"];

        $this->postJson('/api/broadcasting/auth', $request($ana))->assertUnauthorized();

        Sanctum::actingAs($ana);
        $this->postJson('/api/broadcasting/auth', $request($ana))->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/api/broadcasting/auth', $request($luis))->assertForbidden();
    }

    public function test_rejecting_evidence_notifies_the_reason_and_does_not_notify_a_level_drop(): void
    {
        Level::create(['name' => 'Semilla', 'min_points' => 0]);
        Level::create(['name' => 'Explorador', 'min_points' => 100]);
        $user = $this->user('ana@example.com');
        $moderator = $this->user('mod@example.com');
        $challenge = Challenge::create(['title' => 'Aves de humedal', 'reward_points' => 150, 'target_value' => 1]);
        $observation = $this->evidence($user, $challenge);
        $user->notifications()->delete();

        app(ChallengeService::class)->reject($observation, $moderator, 'La foto no muestra la especie.');

        $notifications = $user->notifications()->get();
        $this->assertCount(1, $notifications);
        $this->assertSame('eco.challenge.evidence_rejected', $notifications->first()->type);
        $this->assertStringContainsString('La foto no muestra la especie.', $notifications->first()->data['body']);
    }

    public function test_the_api_lists_only_app_notifications_and_marks_them_as_read(): void
    {
        $user = $this->user('ana@example.com');
        $challenge = Challenge::create(['title' => 'Aves de humedal', 'reward_points' => 0, 'target_value' => 1]);
        $this->evidence($user, $challenge);

        // Aviso interno del panel en la misma tabla: no debe verse en la app.
        $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'Filament\\Notifications\\DatabaseNotification',
            'data' => ['title' => 'Nuevo caso de moderación'],
        ]);

        Sanctum::actingAs($user);

        $id = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'challenge.completed')
            ->assertJsonPath('data.0.category', 'challenge')
            ->assertJsonPath('data.0.action_url', "/retos/{$challenge->id}")
            ->assertJsonPath('data.0.read_at', null)
            ->assertJsonPath('meta.unread_count', 1)
            ->json('data.0.id');

        $this->getJson('/api/notifications/unread-count')->assertOk()->assertJsonPath('count', 1);
        $this->patchJson("/api/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/notifications?filter=unread')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('count', 0);
    }

    public function test_users_cannot_touch_someone_elses_notifications(): void
    {
        $owner = $this->user('ana@example.com');
        $this->evidence($owner, Challenge::create(['title' => 'Reto', 'target_value' => 1]));
        $id = $owner->notifications()->value('id');

        Sanctum::actingAs($this->user('otro@example.com'));

        $this->patchJson("/api/notifications/{$id}/read")->assertNotFound();
        $this->deleteJson("/api/notifications/{$id}")->assertNotFound();
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('updated', 0);
        $this->assertNull($owner->notifications()->value('read_at'));
    }

    public function test_publishing_an_announcement_notifies_active_users_once(): void
    {
        $active = $this->user('ana@example.com');
        $inactive = $this->user('baja@example.com', ['is_active' => false]);

        $announcement = Announcement::create([
            'title' => 'Temporada de aves migratorias',
            'slug' => 'aves-migratorias',
            'summary' => '<p>Únete del 1 al 15 de noviembre.</p>',
            'status' => Announcement::STATUS_PUBLISHED,
        ]);

        // Despublicar y volver a publicar no duplica el aviso.
        $announcement->update(['status' => Announcement::STATUS_DRAFT]);
        $announcement->update(['status' => Announcement::STATUS_PUBLISHED]);

        $this->assertSame(1, $active->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());
        $notification = $active->notifications()->first();
        $this->assertSame('eco.announcement.published', $notification->type);
        $this->assertSame('Únete del 1 al 15 de noviembre.', $notification->data['body']);
        $this->assertSame('/announcements/aves-migratorias', $notification->data['action_url']);
    }

    public function test_a_future_announcement_waits_for_its_start_date(): void
    {
        $user = $this->user('ana@example.com');

        Announcement::create([
            'title' => 'Próximo evento',
            'slug' => 'proximo-evento',
            'status' => Announcement::STATUS_PUBLISHED,
            'starts_at' => now()->addDay(),
        ]);

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_activating_a_challenge_notifies_the_community(): void
    {
        $user = $this->user('ana@example.com');

        $challenge = Challenge::create(['title' => 'Polinizadores', 'reward_points' => 80, 'is_active' => false]);
        $this->assertSame(0, $user->notifications()->count());

        $challenge->update(['is_active' => true]);

        $notification = $user->notifications()->first();
        $this->assertSame('eco.challenge.published', $notification->type);
        $this->assertSame('Nuevo reto: Polinizadores', $notification->data['title']);
    }

    private function user(string $email, array $attributes = []): User
    {
        return User::create(array_merge([
            'full_name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('secret'),
        ], $attributes));
    }

    private function evidence(User $user, Challenge $challenge): Observation
    {
        $observation = Observation::create(['user_id' => $user->id]);
        app(ChallengeService::class)->submit($observation, $challenge);

        return $observation->fresh();
    }
}
