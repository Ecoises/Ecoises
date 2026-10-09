<?php

namespace Tests\Feature;

use App\Models\Challenge;
use App\Models\Observation;
use App\Models\Taxa;
use App\Models\User;
use App\Services\Gamification\ChallengeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChallengeFlowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name')->nullable();
            $table->text('avatar')->nullable();
            $table->string('email')->unique();
            $table->string('password');
            $table->integer('total_score')->default(0);
            $table->string('level')->nullable();
            $table->timestamps();
        });

        Schema::create('taxa', function (Blueprint $table): void {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->string('common_name')->nullable();
            $table->string('kingdom')->nullable();
            $table->string('family')->nullable();
            $table->string('genus')->nullable();
            $table->string('conservation_status')->nullable();
            $table->boolean('is_native')->nullable();
            $table->boolean('is_endemic')->nullable();
            $table->boolean('is_introduced')->nullable();
            $table->integer('observation_count')->default(0);
            $table->string('sync_status')->default('synced');
            $table->timestamps();
        });

        Schema::create('taxon_api_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxon_id');
            $table->string('api_source');
            $table->string('external_id')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('last_verified_at')->nullable();
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
            $table->boolean('requires_photo')->default(true);
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
            $table->unique(['user_id', 'challenge_id']);
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
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('location_name')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_public')->default(true);
            $table->integer('points_awarded')->default(0);
            $table->timestamps();
        });

        Schema::create('observation_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('observation_id');
            $table->text('photo_url');
            $table->boolean('is_primary')->default(false);
            $table->text('caption')->nullable();
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
            $table->unique(['user_id', 'transaction_type', 'reference_type', 'reference_id']);
        });

        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->integer('min_points')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function test_a_free_observation_does_not_award_points(): void
    {
        [$user, $taxon] = $this->userAndTaxon();
        Sanctum::actingAs($user);

        $this->post('/api/observations', ['taxon_id' => $taxon->id], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('challenge_progress', null);

        $this->assertSame(0, $user->fresh()->total_score);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_completing_a_challenge_awards_its_points_once(): void
    {
        [$user, $taxon] = $this->userAndTaxon();
        $challenge = $this->challenge($taxon, target: 2, points: 120);
        Sanctum::actingAs($user);

        $this->submit($challenge, $taxon)
            ->assertCreated()
            ->assertJsonPath('challenge_progress.progress', 1)
            ->assertJsonPath('challenge_progress.completed', false);
        $this->assertSame(0, $user->fresh()->total_score);

        $this->submit($challenge, $taxon)
            ->assertCreated()
            ->assertJsonPath('challenge_progress.completed', true)
            ->assertJsonPath('challenge_progress.points', 120);

        // Un tercer avistamiento no vuelve a pagar el reto
        $this->submit($challenge, $taxon)->assertCreated();

        $this->assertSame(120, $user->fresh()->total_score);
        $this->assertDatabaseCount('point_transactions', 1);
        $this->assertSame(1, $challenge->fresh()->participant_count);
    }

    public function test_evidence_must_match_the_challenge_species_and_include_a_photo(): void
    {
        [$user, $taxon] = $this->userAndTaxon();
        $other = Taxa::create(['scientific_name' => 'Tangara gyrola']);
        $challenge = $this->challenge($taxon);
        Sanctum::actingAs($user);

        $this->submit($challenge, $other)->assertUnprocessable()->assertJsonValidationErrors('taxon_id');

        $this->post('/api/observations', [
            'taxon_id' => $taxon->id,
            'challenge_id' => $challenge->id,
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photos');

        $this->assertDatabaseCount('observations', 0);
    }

    public function test_an_expired_challenge_rejects_new_evidence(): void
    {
        [$user, $taxon] = $this->userAndTaxon();
        $challenge = $this->challenge($taxon);
        $challenge->update(['end_date' => now()->subDay()]);
        Sanctum::actingAs($user);

        $this->submit($challenge, $taxon)->assertUnprocessable()->assertJsonValidationErrors('challenge_id');
    }

    public function test_rejecting_evidence_reverts_the_reward(): void
    {
        [$user, $taxon] = $this->userAndTaxon();
        $moderator = User::create(['full_name' => 'Moderadora', 'email' => 'mod@example.com', 'password' => Hash::make('secret')]);
        $challenge = $this->challenge($taxon, target: 1, points: 80);
        Sanctum::actingAs($user);

        $observationId = $this->submit($challenge, $taxon)->json('data.id');
        $this->assertSame(80, $user->fresh()->total_score);

        $participation = app(ChallengeService::class)->reject(Observation::find($observationId), $moderator, 'La foto no muestra la especie');

        $this->assertFalse($participation->completed);
        $this->assertSame(0, $user->fresh()->total_score);
        $this->assertDatabaseCount('point_transactions', 0);
    }

    public function test_the_challenge_list_includes_the_users_progress(): void
    {
        [$user, $taxon] = $this->userAndTaxon();
        $challenge = $this->challenge($taxon, target: 3);
        Challenge::create(['title' => 'Inactivo', 'is_active' => false, 'reward_points' => 10]);
        Sanctum::actingAs($user);
        $this->submit($challenge, $taxon);

        $this->getJson('/api/challenges')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.taxon.scientific_name', 'Ramphocelus dimidiatus')
            ->assertJsonPath('data.0.my_progress.progress', 1)
            ->assertJsonPath('data.0.target', 3);
    }

    private function userAndTaxon(): array
    {
        return [
            User::create(['full_name' => 'Ana', 'email' => 'ana@example.com', 'password' => Hash::make('secret')]),
            Taxa::create(['scientific_name' => 'Ramphocelus dimidiatus', 'common_name' => 'Asoma terciopelo']),
        ];
    }

    private function challenge(Taxa $taxon, int $target = 1, int $points = 50): Challenge
    {
        return Challenge::create([
            'title' => "Observa {$taxon->common_name}",
            'challenge_type' => Challenge::TYPE_OBSERVATION,
            'taxon_id' => $taxon->id,
            'target_value' => $target,
            'reward_points' => $points,
            'requires_photo' => true,
            'is_active' => true,
        ]);
    }

    private function submit(Challenge $challenge, Taxa $taxon)
    {
        return $this->post('/api/observations', [
            'taxon_id' => $taxon->id,
            'challenge_id' => $challenge->id,
            'photos' => [UploadedFile::fake()->image('evidencia.jpg')],
        ], ['Accept' => 'application/json']);
    }
}
