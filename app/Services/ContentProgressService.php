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
                ->where('is_correct', true) // Asumiendo que is_correct define "aprobado"
                ->exists();

            if (!$hasPassed) {
                throw new Exception("Debes completar y aprobar la actividad '{$activity->title}' antes de finalizar la lección.");
            }
        }

        // 3. Actualizar o Crear Progreso de Lección
        $progress = UserLessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id],
            ['enrollment_id' => $enrollment->id]
        );

        if ($progress->status !== 'completed') {
            DB::transaction(function () use ($progress, $user, $lesson, $enrollment) {
                // Marcar como completa
                $progress->update([
                    'status' => 'completed',
                    'completed_at' => Carbon::now(),
                    'points_earned' => $lesson->points, // Snapshot de puntos ganados
                ]);

                // Otorgar Puntos de Gamificación (Si la lección tiene puntos base por verla)
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
     * Recalcula el porcentaje de progreso de la inscripción.
     */
    protected function updateEnrollmentProgress(UserContentEnrollment $enrollment)
    {
        $totalLessons = $enrollment->content->lessons()->count();
        if ($totalLessons > 0) {
            $completedLessons = UserLessonProgress::where('enrollment_id', $enrollment->id)
                ->where('status', 'completed')
                ->count();

            $percentage = ($completedLessons / $totalLessons) * 100;
            
            $updateData = ['progress_percentage' => $percentage];
            
            if ($percentage >= 100 && !$enrollment->completed_at) {
                $updateData['completed_at'] = Carbon::now();
                
                // Bonus por curso completo (si existe)
                $coursePoints = $enrollment->content->courseDetails->completion_points ?? 0;
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
