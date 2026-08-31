<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\EducationalContent;
use App\Models\Lesson;
use App\Models\User;
use App\Models\UserActivityAttempt;
use App\Models\UserContentEnrollment;
use App\Models\UserLessonProgress;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityAttemptService
{
    public function __construct(
        private readonly ActivityEvaluationService $evaluationService,
        private readonly GamificationService $gamificationService,
        private readonly AchievementService $achievementService,
        private readonly ContentProgressService $contentProgressService,
    ) {}

    /** @return array<string, mixed> */
    public function attempt(User $user, Activity $activity, array $answers, ?int $timeTaken = null): array
    {
        $activity->loadMissing('activitable');
        $lesson = $activity->activitable;

        if (! $lesson instanceof Lesson) {
            throw ValidationException::withMessages(['activity' => 'La actividad no pertenece a una lección.']);
        }

        $lesson->loadMissing('content');
        if ($lesson->status !== EducationalContent::STATUS_PUBLISHED
            || ! $lesson->is_published
            || ! $lesson->content?->isPublished()) {
            throw (new ModelNotFoundException)->setModel(Activity::class, [$activity->id]);
        }

        return DB::transaction(function () use ($user, $activity, $lesson, $answers, $timeTaken): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $previousAttempts = UserActivityAttempt::query()
                ->where('user_id', $lockedUser->id)
                ->where('activity_id', $activity->id)
                ->lockForUpdate()
                ->get();

            $successfulAttempt = $previousAttempts->firstWhere('is_correct', true);
            if ($successfulAttempt) {
                return [
                    'attempt' => $successfulAttempt,
                    'is_correct' => true,
                    'already_completed' => true,
                    'points_awarded' => 0,
                    'total_points_awarded' => 0,
                    'feedback' => null,
                    'attempts_remaining' => max(0, (int) $activity->attempts_allowed - $previousAttempts->count()),
                    'achievements' => [],
                ];
            }

            $attemptsAllowed = max(1, (int) $activity->attempts_allowed);
            if ($previousAttempts->count() >= $attemptsAllowed) {
                throw ValidationException::withMessages([
                    'attempts' => 'Ya utilizaste todos los intentos disponibles para esta actividad.',
                ]);
            }

            $evaluation = $this->evaluationService->evaluate($activity, $answers);
            $scoreBefore = (int) $lockedUser->fresh()->total_score;
            $enrollment = UserContentEnrollment::firstOrCreate(
                ['user_id' => $lockedUser->id, 'content_id' => $lesson->content_id],
                [
                    'enrolled_at' => now(),
                    'started_at' => now(),
                    'last_accessed_at' => now(),
                    'progress_percentage' => 0,
                ],
            );
            $progress = UserLessonProgress::firstOrCreate(
                ['user_id' => $lockedUser->id, 'lesson_id' => $lesson->id],
                [
                    'enrollment_id' => $enrollment->id,
                    'status' => 'en_progreso',
                    'started_at' => now(),
                    'last_accessed_at' => now(),
                ],
            );

            $pointsAwarded = 0;
            if ($evaluation['is_correct']) {
                $transaction = $this->gamificationService->awardPoints(
                    $lockedUser,
                    (int) $activity->max_points,
                    Activity::class,
                    $activity->id,
                    "Actividad aprobada: {$activity->title}",
                );
                $pointsAwarded = (int) ($transaction?->points ?? 0);
            }

            $attempt = UserActivityAttempt::create([
                'user_id' => $lockedUser->id,
                'activity_id' => $activity->id,
                'lesson_progress_id' => $progress->id,
                'attempt_number' => $previousAttempts->count() + 1,
                'completed_at' => now(),
                'user_answers' => $answers,
                'is_correct' => $evaluation['is_correct'],
                'points_earned' => $pointsAwarded,
                'time_taken' => $timeTaken,
            ]);

            $this->refreshProgress($lockedUser, $lesson, $progress, $enrollment);
            $this->contentProgressService->refreshEnrollmentProgress($enrollment);
            $achievements = $evaluation['is_correct']
                ? $this->achievementService->evaluate($lockedUser)
                : collect();
            $totalPointsAwarded = max(0, (int) $lockedUser->fresh()->total_score - $scoreBefore);

            return [
                'attempt' => $attempt,
                'is_correct' => $evaluation['is_correct'],
                'already_completed' => false,
                'points_awarded' => $pointsAwarded,
                'total_points_awarded' => $totalPointsAwarded,
                'feedback' => $evaluation['feedback'],
                'attempts_remaining' => max(0, $attemptsAllowed - $attempt->attempt_number),
                'achievements' => $achievements->pluck('achievement')->values(),
            ];
        });
    }

    private function refreshProgress(
        User $user,
        Lesson $lesson,
        UserLessonProgress $progress,
        UserContentEnrollment $enrollment,
    ): void {
        $activities = $lesson->activities()->get();
        $completedIds = UserActivityAttempt::query()
            ->where('user_id', $user->id)
            ->whereIn('activity_id', $activities->pluck('id'))
            ->where('is_correct', true)
            ->pluck('activity_id')
            ->unique();

        $progress->update([
            'last_accessed_at' => now(),
            'total_activities' => $activities->count(),
            'activities_completed' => $completedIds->count(),
            'points_earned' => $activities->whereIn('id', $completedIds)->sum('max_points'),
            'points_possible' => $activities->sum('max_points'),
        ]);
        $enrollment->update([
            'last_accessed_at' => now(),
            'current_lesson_id' => $lesson->id,
        ]);
    }
}
