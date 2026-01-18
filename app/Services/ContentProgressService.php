<?php

namespace App\Services;

use App\Models\User;
use App\Models\Lesson;
use App\Models\UserLessonProgress;
use App\Models\UserContentEnrollment;
use App\Models\EducationalContent;
use App\Models\UserActivityAttempt;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Exception;

class ContentProgressService
{
    protected $gamificationService;

    public function __construct(GamificationService $gamificationService)
    {
        $this->gamificationService = $gamificationService;
    }

    /**
     * Incrementa el contador de visitas de un contenido.
     */
    public function incrementViewCount(EducationalContent $content): void
    {
        $content->increment('view_count');
    }

    /**
     * Inicia el seguimiento de un contenido para un usuario (Inscripción).
     */
    public function startContent(User $user, EducationalContent $content): UserContentEnrollment
    {
        $this->incrementViewCount($content);

        return UserContentEnrollment::firstOrCreate(
            [
                'user_id' => $user->id, 
                'content_id' => $content->id
            ],
            [
                'enrolled_at' => Carbon::now(),
                'started_at' => Carbon::now(),
                'last_accessed_at' => Carbon::now(),
                'progress_percentage' => 0,
            ]
        );
    }

    /**
     * Intenta marcar una lección como completada.
     * Valida que todas las actividades obligatorias estén aprobadas.
     */
    public function completeLesson(User $user, Lesson $lesson)
    {
        // 1. Obtener inscripción
        $enrollment = $this->startContent($user, $lesson->content);

        // 2. Verificar actividades obligatorias
        $activities = $lesson->activities()->where('is_mandatory', true)->get();
        
        foreach ($activities as $activity) {
            $hasPassed = UserActivityAttempt::where('user_id', $user->id)
                ->where('activity_id', $activity->id)
                ->where('is_correct', true) 
                ->exists();

            if (!$hasPassed) {
                throw new Exception("Debes completar y aprobar la actividad '{$activity->title}' antes de finalizar la lección.");
            }
        }

        // 3. Actualizar o Crear Progreso de Lección
        $progress = UserLessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['enrollment_id' => $enrollment->id, 'status' => 'en_progreso', 'started_at' => now()]
        );

        if ($progress->status !== 'completada') {
            DB::transaction(function () use ($progress, $user, $lesson, $enrollment) {
                // Calcular puntos y actividades finales de la lección antes de cerrar
                $totalActivities = $lesson->activities()->count();
                $completedActivitiesCount = UserActivityAttempt::where('user_id', $user->id)
                        ->whereIn('activity_id', $lesson->activities()->pluck('id'))
                        ->where('is_correct', true) 
                        ->pluck('activity_id')
                        ->unique()
                        ->count();
                
                // Calcular puntos basados en actividades (o la lección en sí si no hay actividades puntuables)
                // Aquí asumimos que points_earned es la suma de los puntos de las actividades + puntos base de lección
                $activitiesPoints = $lesson->activities()->sum('max_points');
                $earnedPoints = $activitiesPoints; // Si completó, asumimos que ganó todo si es obligatorio, o recalcular real.
                // Para ser más precisos, recalculamos lo ganado real:
                $realEarnedPoints = $lesson->activities()->get()->reduce(function($carry, $act) use ($user) {
                    $passed = UserActivityAttempt::where('user_id', $user->id)
                        ->where('activity_id', $act->id)
                        ->where('is_correct', true)
                        ->exists();
                    return $carry + ($passed ? $act->max_points : 0);
                }, 0);

                // Marcar como completa
                $progress->update([
                    'status' => 'completada',
                    'completed_at' => Carbon::now(),
                    'last_accessed_at' => Carbon::now(),
                    'total_activities' => $totalActivities,
                    'activities_completed' => $completedActivitiesCount,
                    'points_earned' => $realEarnedPoints + $lesson->points, // Puntos actividades + Puntos base lección
                    'points_possible' => $activitiesPoints + $lesson->points,
                ]);

                // Otorgar Puntos de Gamificación (Puntos base de la lección)
                if ($lesson->points > 0) {
                    $this->gamificationService->awardPoints(
                        $user, 
                        $lesson->points, 
                        Lesson::class, 
                        $lesson->id, 
                        "Lección completada: {$lesson->title}"
                    );
                }

                // Actualizar progreso general del curso/inscripción
                $this->updateEnrollmentProgress($enrollment);
            });
        }
        
        return $progress;
    }

    /**
     * Recalcula el porcentaje y estadísticas de progreso de la inscripción.
     */
    protected function updateEnrollmentProgress(UserContentEnrollment $enrollment)
    {
        $totalLessons = $enrollment->content->lessons()->count();
        
        if ($totalLessons > 0) {
            // Obtener todos los progresos de lecciones para esta inscripción
            $lessonProgresses = UserLessonProgress::where('enrollment_id', $enrollment->id)->get();
            
            $completedLessons = $lessonProgresses->where('status', 'completada')->count();
            $percentage = ($completedLessons / $totalLessons) * 100;
            
            // Calcular totales agregados
            $totalPointsEarned = $lessonProgresses->sum('points_earned');
            $totalPointsPossible = $lessonProgresses->sum('points_possible'); // Esto suma solo de las intentadas/creadas. 
            // Para total_points_possible real del curso, deberíamos sumar de todas las lecciones del contenido, no solo las progresadas.
            // Pero para 'UserContentEnrollment', a veces se prefiere 'posible hasta ahora' o 'posible total'. 
            // Vamos a usar 'posible total del curso' consultando las lecciones directamente para ser más exactos en "qué falta".
            
            $allLessons = $enrollment->content->lessons()->with('activities')->get();
            $courseTotalPointsPossible = $allLessons->sum(function($l) {
                return $l->points + $l->activities->sum('max_points');
            });

            $totalTimeSpent = $lessonProgresses->sum('time_spent'); // Asumiendo que time_spent se trauckea en algún lado (frontend debe enviarlo)

            $updateData = [
                'progress_percentage' => $percentage,
                'lessons_completed' => $completedLessons,
                'total_lessons' => $totalLessons,
                'total_points_earned' => $totalPointsEarned,
                'total_points_possible' => $courseTotalPointsPossible,
                'total_time_spent' => $totalTimeSpent
            ];
            
            if ($percentage >= 100 && !$enrollment->completed_at) {
                $updateData['completed_at'] = Carbon::now();
                $updateData['final_score'] = $totalPointsEarned; // Ejemplo: Score final = puntos ganados
                
                // Bonus por curso completo (si existe)
                $coursePoints = optional($enrollment->content->courseDetails)->completion_points ?? 0;
                if ($coursePoints > 0) {
                     $this->gamificationService->awardPoints(
                        $enrollment->user, 
                        $coursePoints, 
                        EducationalContent::class, 
                        $enrollment->content_id, 
                        "Curso completado: {$enrollment->content->title}"
                    );
                }
            }

            $enrollment->update($updateData);
        }
    }
}
