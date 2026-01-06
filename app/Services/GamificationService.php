<?php

namespace App\Services;

use App\Models\User;
use App\Models\Level;
use App\Models\PointTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GamificationService
{
    /**
     * Otorga puntos a un usuario de manera segura (idempotente).
     *
     * @param User $user El usuario a premiar.
     * @param int $points Cantidad de puntos.
     * @param string $sourceType Clase del modelo origen (ej: Lesson::class).
     * @param int $sourceId ID del modelo origen.
     * @param string $description Descripción para el historial.
     * @return PointTransaction|null Retorna la transacción si se creó, o null si ya existía.
     */
    public function awardPoints(User $user, int $points, string $sourceType, int $sourceId, string $description = 'Puntos ganados'): ?PointTransaction
    {
        if ($points <= 0) {
            return null;
        }

        // 1. Check Idempotency (Anti-Scam)
        $existingTransaction = PointTransaction::where('user_id', $user->id)
            ->where('reference_type', $sourceType)
            ->where('reference_id', $sourceId)
            ->first();

        if ($existingTransaction) {
            // Ya se otorgaron puntos por esta acción específica
            return null;
        }

        return DB::transaction(function () use ($user, $points, $sourceType, $sourceId, $description) {
            // 2. Create Transaction
            $transaction = PointTransaction::create([
                'user_id' => $user->id,
                'points' => $points,
                'transaction_type' => 'earned', // 'earned' | 'spent'
                'reference_type' => $sourceType,
                'reference_id' => $sourceId,
                'description' => $description,
            ]);

            // 3. Update User Score
            $user->increment('total_score', $points);
            
            // 4. Check Level Up
            $this->checkLevelUp($user);

            return $transaction;
        });
    }

    /**
     * Verifica y actualiza el nivel del usuario basado en sus puntos actuales.
     *
     * @param User $user
     * @return void
     */
    public function checkLevelUp(User $user): void
    {
        // Obtener el nivel más alto que el usuario alcanza con sus puntos actuales
        $appropriateLevel = Level::where('is_active', true)
            ->where('min_points', '<=', $user->total_score)
            ->orderBy('min_points', 'desc')
            ->first();

        if ($appropriateLevel && $appropriateLevel->name !== $user->level) {
            // Actualizar nivel
            // Asumimos que el campo 'level' en users es un string con el nombre, 
            // o podríamos cambiarlo a level_id si el usuario prefiere relación FK.
            // Por ahora mantenemos string según User.php existente.
            
            $oldLevel = $user->level;
            $user->update(['level' => $appropriateLevel->name]);
            
            // Aquí se podría disparar un evento LevelUp event
            Log::info("User {$user->id} leveled up from {$oldLevel} to {$appropriateLevel->name}");
        }
    }
}
