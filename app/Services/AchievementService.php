<?php

namespace App\Services;

use App\Models\User;
use App\Models\Observation;
use App\Models\Achievement;
use App\Models\UserAchievement;

class AchievementService
{
    /**
     * Verificar y otorgar logros basados en una acción
     */
    public function checkAchievements(User $user, Observation $observation): void
    {
        $achievements = Achievement::where('is_active', true)->get();

        foreach ($achievements as $achievement) {
            switch ($achievement->requirement_type) {
                case 'count':
                    if ($achievement->category === 'observador') {
                        $observationCount = $user->observations()
                            ->where('location_id', $observation->location_id)
                            ->count();
                        if ($observationCount >= $achievement->requirement_value) {
                            UserAchievement::firstOrCreate([
                                'user_id' => $user->id,
                                'achievement_id' => $achievement->id
                            ], [
                                'earned_at' => now(),
                                'progress_data' => json_encode(['observation_count' => $observationCount])
                            ]);

                            // Otorgar puntos por logro
                            if ($achievement->points > 0) {
                                \App\Models\PointTransaction::create([
                                    'user_id' => $user->id,
                                    'points' => $achievement->points,
                                    'transaction_type' => 'logro',
                                    'reference_id' => $achievement->id,
                                    'reference_type' => 'achievement',
                                    'description' => 'Logro obtenido: ' . $achievement->name
                                ]);
                                $user->increment('total_score', $achievement->points);
                                $user->increment('experience_points', $achievement->points);
                            }
                        }
                    }
                    break;

                case 'diversidad':
                    $speciesCount = $user->observations()
                        ->whereNotNull('taxon_id')
                        ->where('location_id', $observation->location_id)
                        ->distinct('taxon_id')
                        ->count();
                    if ($speciesCount >= $achievement->requirement_value) {
                        UserAchievement::firstOrCreate([
                            'user_id' => $user->id,
                            'achievement_id' => $achievement->id
                        ], [
                            'earned_at' => now(),
                            'progress_data' => json_encode(['species_count' => $speciesCount])
                        ]);

                        if ($achievement->points > 0) {
                            \App\Models\PointTransaction::create([
                                'user_id' => $user->id,
                                'points' => $achievement->points,
                                'transaction_type' => 'logro',
                                'reference_id' => $achievement->id,
                                'reference_type' => 'achievement',
                                'description' => 'Logro obtenido: ' . $achievement->name
                            ]);
                            $user->increment('total_score', $achievement->points);
                            $user->increment('experience_points', $achievement->points);
                        }
                    }
                    break;

                // Añadir más casos para otros requirement_type (streak, quality, collaboration)
            }
        }
    }
}