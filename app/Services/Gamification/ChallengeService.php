<?php

namespace App\Services\Gamification;

use App\Models\Challenge;
use App\Models\ChallengeParticipation;
use App\Models\Observation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retos de campo basados en avistamientos.
 *
 * Flujo:
 *  1. El usuario sube un avistamiento indicando el reto (challenge_id).
 *  2. assertCanSubmit() valida que el reto esté vigente y que la especie coincida.
 *  3. submit() aprueba automáticamente la evidencia cuando cumple las reglas
 *     (especie correcta y foto, si el reto la exige) y actualiza el progreso.
 *  4. Al alcanzar target_value avistamientos aprobados el reto queda completado
 *     y se otorgan reward_points (una sola vez por reto) y la insignia, si existe.
 *  5. Un moderador puede rechazar la evidencia desde el panel; si el usuario
 *     baja del objetivo, el reto vuelve a "en progreso" y se revierten los puntos.
 */
class ChallengeService
{
    public function __construct(
        private readonly GamificationService $gamification,
        private readonly AchievementService $achievements,
    ) {}

    /**
     * Valida, antes de guardar nada, que el avistamiento puede contar para el reto.
     *
     * @throws ValidationException
     */
    public function assertCanSubmit(Challenge $challenge, ?int $taxonId, int $photoCount): void
    {
        if (! $challenge->isAvailable()) {
            throw ValidationException::withMessages(['challenge_id' => 'Este reto no está activo.']);
        }

        if ($challenge->taxon_id && $challenge->taxon_id !== $taxonId) {
            throw ValidationException::withMessages(['taxon_id' => 'La especie del avistamiento no corresponde a la del reto.']);
        }

        if ($challenge->requires_photo && $photoCount === 0) {
            throw ValidationException::withMessages(['photos' => 'Este reto necesita al menos una foto como evidencia.']);
        }
    }

    /**
     * Registra el avistamiento como evidencia y devuelve la participación actualizada.
     */
    public function submit(Observation $observation, Challenge $challenge): ChallengeParticipation
    {
        return DB::transaction(function () use ($observation, $challenge): ChallengeParticipation {
            $observation->update([
                'challenge_id' => $challenge->id,
                'verification_status' => Observation::STATUS_APPROVED,
                'reviewed_at' => now(),
            ]);

            return $this->syncProgress($observation->user, $challenge);
        });
    }

    /**
     * Rechazo manual desde el panel de moderación.
     */
    public function reject(Observation $observation, User $reviewer, ?string $note = null): ChallengeParticipation
    {
        return DB::transaction(function () use ($observation, $reviewer, $note): ChallengeParticipation {
            $observation->update([
                'verification_status' => Observation::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            return $this->syncProgress($observation->user, $observation->challenge);
        });
    }

    /**
     * Aprobación manual (por ejemplo, para revertir un rechazo).
     */
    public function approve(Observation $observation, User $reviewer): ChallengeParticipation
    {
        return DB::transaction(function () use ($observation, $reviewer): ChallengeParticipation {
            $observation->update([
                'verification_status' => Observation::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => null,
            ]);

            return $this->syncProgress($observation->user, $observation->challenge);
        });
    }

    /**
     * Recalcula el progreso desde los avistamientos aprobados, así el estado
     * nunca se desincroniza aunque se aprueben o rechacen evidencias en cualquier orden.
     */
    public function syncProgress(User $user, Challenge $challenge): ChallengeParticipation
    {
        $participation = ChallengeParticipation::firstOrCreate(
            ['user_id' => $user->id, 'challenge_id' => $challenge->id],
            ['joined_at' => now()],
        );

        if ($participation->wasRecentlyCreated) {
            $challenge->increment('participant_count');
        }

        $approved = $challenge->observations()
            ->where('user_id', $user->id)
            ->where('verification_status', Observation::STATUS_APPROVED)
            ->count();
        $completed = $approved >= $challenge->requiredObservations();
        $wasCompleted = $participation->completed;

        $participation->update([
            'progress_value' => $approved,
            'completed' => $completed,
            'completed_at' => $completed ? ($participation->completed_at ?? now()) : null,
        ]);

        if ($completed && ! $wasCompleted) {
            $this->reward($user, $challenge);
        } elseif (! $completed && $wasCompleted) {
            $this->gamification->revokePoints($user, Challenge::class, $challenge->id);
        }

        return $participation->refresh();
    }

    private function reward(User $user, Challenge $challenge): void
    {
        $this->gamification->awardPoints(
            $user,
            (int) $challenge->reward_points,
            Challenge::class,
            $challenge->id,
            "Reto completado: {$challenge->title}",
        );

        if ($challenge->rewardBadge) {
            $this->achievements->award($user, $challenge->rewardBadge, ['challenge_id' => $challenge->id]);
        }

        $this->achievements->evaluate($user);
    }
}
