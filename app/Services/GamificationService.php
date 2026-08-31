<?php

namespace App\Services;

use App\Models\Level;
use App\Models\PointTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GamificationService
{
    /**
     * Otorga puntos a un usuario de manera segura (idempotente).
     *
     * @param  User  $user  El usuario a premiar.
     * @param  int  $points  Cantidad de puntos.
     * @param  string  $sourceType  Clase del modelo origen (ej: Lesson::class).
     * @param  int  $sourceId  ID del modelo origen.
     * @param  string  $description  Descripción para el historial.
     * @return PointTransaction|null Retorna la transacción si se creó, o null si ya existía.
     */
    public function awardPoints(User $user, int $points, string $sourceType, int $sourceId, string $description = 'Puntos ganados'): ?PointTransaction
    {
        if ($points <= 0) {
            return null;
        }

        return DB::transaction(function () use ($user, $points, $sourceType, $sourceId, $description) {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $transaction = PointTransaction::firstOrCreate([
                'user_id' => $user->id,
                'transaction_type' => 'earned',
                'reference_type' => $sourceType,
                'reference_id' => $sourceId,
            ], [
                'points' => $points,
                'description' => $description,
            ]);

            if (! $transaction->wasRecentlyCreated) {
                return null;
            }

            $lockedUser->increment('total_score', $points);
            $this->checkLevelUp($lockedUser->refresh());
            $user->refresh();

            return $transaction;
        });
    }

    /**
     * Verifica y actualiza el nivel del usuario basado en sus puntos actuales.
     */
    public function checkLevelUp(User $user): void
    {
        // Obtener el nivel más alto que el usuario alcanza con sus puntos actuales
        $appropriateLevel = Level::where('is_active', true)
            ->where('min_points', '<=', $user->total_score)
            ->orderBy('min_points', 'desc')
            ->first();

        if ($appropriateLevel && $appropriateLevel->id !== (int) $user->level) {
            $oldLevel = $user->level;
            $user->update(['level' => $appropriateLevel->id]);

            // Aquí se podría disparar un evento LevelUp event
            Log::info("User {$user->id} leveled up from {$oldLevel} to {$appropriateLevel->name}");
        }
    }
}
