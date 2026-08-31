<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\UserActivityAttempt;
use App\Models\UserArticleProgress;
use App\Models\UserContentEnrollment;
use App\Models\UserLessonProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AchievementService
{
    public function __construct(private readonly GamificationService $gamificationService) {}

    /** @return Collection<int, UserAchievement> */
    public function evaluate(User $user): Collection
    {
        $earned = collect();

        foreach (Achievement::query()->where('is_active', true)->get() as $achievement) {
            $current = $this->currentValue($user, $achievement->requirement_type);
            $target = max(1, (int) data_get($achievement->requirement_criteria, 'count', 1));

            if ($current >= $target) {
                $award = $this->award($user, $achievement, [
                    'current' => $current,
                    'target' => $target,
                    'requirement_type' => $achievement->requirement_type,
                ]);

                if ($award?->wasRecentlyCreated) {
                    $earned->push($award->load('achievement'));
                }
            }
        }

        return $earned;
    }

    public function award(User $user, Achievement $achievement, array $progressData = []): ?UserAchievement
    {
        if (! $achievement->is_active) {
            return null;
        }

        return DB::transaction(function () use ($user, $achievement, $progressData): UserAchievement {
            $award = UserAchievement::firstOrCreate(
                ['user_id' => $user->id, 'achievement_id' => $achievement->id],
                ['earned_at' => now(), 'progress_data' => $progressData],
            );

            if ($award->wasRecentlyCreated && $achievement->points > 0) {
                $this->gamificationService->awardPoints(
                    $user,
                    (int) $achievement->points,
                    Achievement::class,
                    $achievement->id,
                    "Logro desbloqueado: {$achievement->name}",
                );
            }

            return $award;
        });
    }

    private function currentValue(User $user, string $requirementType): int
    {
        return match ($requirementType) {
            'activities_completed' => UserActivityAttempt::query()
                ->where('user_id', $user->id)
                ->where('is_correct', true)
                ->distinct('activity_id')
                ->count('activity_id'),
            'lessons_completed' => UserLessonProgress::query()
                ->where('user_id', $user->id)
                ->where('status', 'completada')
                ->count(),
            'courses_completed' => UserContentEnrollment::query()
                ->where('user_id', $user->id)
                ->whereNotNull('completed_at')
                ->whereHas('content', fn ($query) => $query->where('content_type', 'course'))
                ->count(),
            'articles_completed' => UserArticleProgress::query()
                ->where('user_id', $user->id)
                ->where('status', 'completada')
                ->count(),
            'total_points' => max(0, (int) $user->fresh()->total_score),
            default => 0,
        };
    }
}
