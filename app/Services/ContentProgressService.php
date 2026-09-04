<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\EducationalContent;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserActivityAttempt;
use App\Models\UserArticleProgress;
use App\Models\UserContentEnrollment;
use App\Models\UserLessonProgress;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class ContentProgressService
{
    public function __construct(
        protected GamificationService $gamificationService,
        protected AchievementService $achievementService,
        protected CertificateService $certificateService,
    ) {}

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
        $this->ensurePrerequisitesAreCompleted($user, $content);

        $enrollment = UserContentEnrollment::firstOrCreate(
            [
                'user_id' => $user->id,
                'content_id' => $content->id,
            ],
            [
                'enrolled_at' => Carbon::now(),
                'started_at' => Carbon::now(),
                'last_accessed_at' => Carbon::now(),
                'progress_percentage' => 0,
            ]
        );

        if ($enrollment->wasRecentlyCreated) {
            $this->incrementViewCount($content);
        } else {
            $enrollment->update(['last_accessed_at' => now()]);
        }

        if ($content->isArticle()) {
            UserArticleProgress::firstOrCreate(
                ['user_id' => $user->id, 'article_id' => $content->id],
                [
                    'enrollment_id' => $enrollment->id,
                    'status' => 'en_progreso',
                    'started_at' => now(),
                    'last_accessed_at' => now(),
                    'reading_progress' => 0,
                ],
            );
        } elseif ($content->isCourse()) {
            $lessons = $content->lessons()->with('activities')->get();
            $enrollment->update([
                'total_lessons' => $lessons->count(),
                'total_points_possible' => $lessons->sum(
                    fn (Lesson $lesson): int => (int) $lesson->points + (int) $lesson->activities->sum('max_points')
                ),
            ]);
        }

        return $enrollment;
    }

    /**
     * Intenta marcar una lección como completada.
     * Valida que todas las actividades obligatorias estén aprobadas.
     */
    public function completeLesson(User $user, Lesson $lesson): UserLessonProgress
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

            if (! $hasPassed) {
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

                $activitiesPoints = $lesson->activities()->sum('max_points');
                $realEarnedPoints = $lesson->activities()->get()->reduce(function ($carry, $act) use ($user) {
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
                $this->refreshEnrollmentProgress($enrollment);
            });
        }

        $progress->refresh();
        $progress->setAttribute(
            'achievements',
            $this->achievementService->evaluate($user)->pluck('achievement')->values(),
        );

        return $progress;
    }

    public function updateArticleProgress(
        User $user,
        EducationalContent $article,
        float $readingProgress,
        ?int $lastPosition = null,
        int $timeSpent = 0,
    ): UserArticleProgress {
        if (! $article->isArticle()) {
            throw new Exception('Este contenido no es un artículo.');
        }

        $enrollment = $this->startContent($user, $article);

        return DB::transaction(function () use ($user, $article, $enrollment, $readingProgress, $lastPosition, $timeSpent): UserArticleProgress {
            $progress = UserArticleProgress::query()
                ->where('user_id', $user->id)
                ->where('article_id', $article->id)
                ->lockForUpdate()
                ->firstOrFail();
            $percentage = min(100, max((float) $progress->reading_progress, $readingProgress));
            $completed = $percentage >= 100;
            $wasCompleted = $progress->status === 'completada';

            $progress->update([
                'status' => $completed ? 'completada' : 'en_progreso',
                'reading_progress' => $percentage,
                'last_position' => $lastPosition ?? $progress->last_position,
                'last_accessed_at' => now(),
                'completed_at' => $completed ? ($progress->completed_at ?? now()) : null,
                'time_spent' => (int) $progress->time_spent + max(0, $timeSpent),
            ]);
            $enrollment->update([
                'progress_percentage' => $percentage,
                'completed_at' => $completed ? ($enrollment->completed_at ?? now()) : null,
                'last_accessed_at' => now(),
                'total_time_spent' => (int) $progress->fresh()->time_spent,
                'final_score' => $completed ? 100 : null,
            ]);

            $achievements = $completed && ! $wasCompleted
                ? $this->achievementService->evaluate($user)->pluck('achievement')->values()
                : collect();

            return $progress->refresh()->setAttribute('achievements', $achievements);
        });
    }

    /**
     * Recalcula el porcentaje y estadísticas de progreso de la inscripción.
     */
    public function refreshEnrollmentProgress(UserContentEnrollment $enrollment): UserContentEnrollment
    {
        $totalLessons = $enrollment->content->lessons()->count();

        if ($totalLessons > 0) {
            // Obtener todos los progresos de lecciones para esta inscripción
            $lessonProgresses = UserLessonProgress::where('enrollment_id', $enrollment->id)->get();

            $completedLessons = $lessonProgresses->where('status', 'completada')->count();
            $percentage = ($completedLessons / $totalLessons) * 100;

            // Calcular totales agregados
            $totalPointsEarned = $lessonProgresses->sum('points_earned');
            $allLessons = $enrollment->content->lessons()->with('activities')->get();
            $courseTotalPointsPossible = $allLessons->sum(function ($l) {
                return $l->points + $l->activities->sum('max_points');
            });

            $totalTimeSpent = $lessonProgresses->sum('time_spent');

            $updateData = [
                'progress_percentage' => $percentage,
                'lessons_completed' => $completedLessons,
                'total_lessons' => $totalLessons,
                'total_points_earned' => $totalPointsEarned,
                'total_points_possible' => $courseTotalPointsPossible,
                'total_time_spent' => $totalTimeSpent,
            ];

            if ($percentage >= 100 && ! $enrollment->completed_at) {
                $updateData['completed_at'] = Carbon::now();
                $updateData['final_score'] = $courseTotalPointsPossible > 0
                    ? round(($totalPointsEarned / $courseTotalPointsPossible) * 100, 2)
                    : 100;

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

            if ($percentage >= 100) {
                $achievementId = optional($enrollment->content->courseDetails)->achievement_id;
                $achievement = $achievementId ? Achievement::find($achievementId) : null;

                if ($achievement) {
                    $this->achievementService->award($enrollment->user, $achievement, [
                        'content_id' => $enrollment->content_id,
                        'completed_at' => $enrollment->completed_at,
                    ]);
                }

                $this->achievementService->evaluate($enrollment->user);
                $this->certificateService->issueFor($enrollment->fresh(['content.courseDetails']));
            }
        }

        return $enrollment->refresh();
    }

    private function ensurePrerequisitesAreCompleted(User $user, EducationalContent $content): void
    {
        if (! $content->isCourse()) {
            return;
        }

        $requiredIds = collect($content->courseDetails?->prerequisite_content_ids)
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id !== $content->id)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($requiredIds->isEmpty()) {
            return;
        }

        $completedIds = UserContentEnrollment::query()
            ->where('user_id', $user->id)
            ->whereIn('content_id', $requiredIds)
            ->whereNotNull('completed_at')
            ->pluck('content_id');
        $missingIds = $requiredIds->diff($completedIds);

        if ($missingIds->isEmpty()) {
            return;
        }

        $titles = EducationalContent::query()->whereIn('id', $missingIds)->pluck('title')->implode(', ');

        throw \Illuminate\Validation\ValidationException::withMessages([
            'prerequisites' => 'Antes debes completar: '.($titles ?: 'los contenidos requeridos').'.',
        ]);
    }
}
