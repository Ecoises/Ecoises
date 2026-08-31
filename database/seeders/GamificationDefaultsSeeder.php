<?php

namespace Database\Seeders;

use App\Models\Achievement;
use App\Models\Level;
use App\Models\User;
use Illuminate\Database\Seeder;

class GamificationDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['name' => 'Explorador', 'min_points' => 0, 'icon' => '🌱', 'color' => '#65A30D'],
            ['name' => 'Observador', 'min_points' => 250, 'icon' => '🔎', 'color' => '#16A34A'],
            ['name' => 'Naturalista', 'min_points' => 750, 'icon' => '🦋', 'color' => '#0891B2'],
            ['name' => 'Guardián', 'min_points' => 1500, 'icon' => '🌳', 'color' => '#047857'],
            ['name' => 'Embajador', 'min_points' => 3000, 'icon' => '🏅', 'color' => '#CA8A04'],
        ] as $level) {
            Level::updateOrCreate(
                ['min_points' => $level['min_points']],
                array_merge($level, ['is_active' => true]),
            );
        }

        $startingLevel = Level::query()->orderBy('min_points')->first();
        if ($startingLevel) {
            User::query()
                ->where(function ($query): void {
                    $query->whereNull('level')
                        ->orWhereNotIn('level', Level::query()->select('id'));
                })
                ->update(['level' => $startingLevel->id]);
        }

        foreach ([
            [
                'name' => 'Primer desafío',
                'description' => 'Aprobaste tu primera actividad educativa.',
                'icon_url' => '🎯',
                'category' => 'aprendizaje',
                'points' => 10,
                'requirement_type' => 'activities_completed',
                'requirement_criteria' => ['count' => 1],
                'rarity' => 'común',
            ],
            [
                'name' => 'Primera lección',
                'description' => 'Completaste tu primera lección.',
                'icon_url' => '📗',
                'category' => 'aprendizaje',
                'points' => 20,
                'requirement_type' => 'lessons_completed',
                'requirement_criteria' => ['count' => 1],
                'rarity' => 'común',
            ],
            [
                'name' => 'Curso completado',
                'description' => 'Finalizaste un curso completo de conservación.',
                'icon_url' => '🎓',
                'category' => 'conservación',
                'points' => 50,
                'requirement_type' => 'courses_completed',
                'requirement_criteria' => ['count' => 1],
                'rarity' => 'raro',
            ],
            [
                'name' => 'Lector de biodiversidad',
                'description' => 'Completaste tu primer artículo educativo.',
                'icon_url' => '📖',
                'category' => 'aprendizaje',
                'points' => 15,
                'requirement_type' => 'articles_completed',
                'requirement_criteria' => ['count' => 1],
                'rarity' => 'común',
            ],
        ] as $achievement) {
            Achievement::updateOrCreate(
                ['name' => $achievement['name']],
                array_merge($achievement, ['is_active' => true]),
            );
        }
    }
}
