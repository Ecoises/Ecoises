<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Models\UserContentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LearningDashboardController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['currentLevel', 'achievements.achievement']);
        $enrollments = UserContentEnrollment::query()
            ->where('user_id', $user->id)
            ->with('content:id,title,slug,content_type,description,thumbnail_url,difficulty_level,estimated_duration')
            ->latest('last_accessed_at')
            ->get();

        $currentLevel = $user->currentLevel
            ?? Level::query()->where('is_active', true)->where('min_points', '<=', $user->total_score)->orderByDesc('min_points')->first();
        $nextLevel = Level::query()
            ->where('is_active', true)
            ->where('min_points', '>', $user->total_score)
            ->orderBy('min_points')
            ->first();
        $levelBase = (int) ($currentLevel?->min_points ?? 0);
        $levelRange = max(1, (int) ($nextLevel?->min_points ?? max($user->total_score, 1)) - $levelBase);
        $levelProgress = $nextLevel
            ? min(100, max(0, (($user->total_score - $levelBase) / $levelRange) * 100))
            : 100;

        return response()->json([
            'learner' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'avatar' => $user->avatar,
                'total_points' => (int) $user->total_score,
                'level' => $currentLevel ? [
                    'id' => $currentLevel->id,
                    'name' => $currentLevel->name,
                    'icon' => $currentLevel->icon,
                    'color' => $currentLevel->color,
                    'min_points' => (int) $currentLevel->min_points,
                ] : null,
                'next_level' => $nextLevel ? [
                    'name' => $nextLevel->name,
                    'min_points' => (int) $nextLevel->min_points,
                    'points_remaining' => max(0, $nextLevel->min_points - $user->total_score),
                ] : null,
                'level_progress' => round($levelProgress, 1),
            ],
            'stats' => [
                'enrolled' => $enrollments->count(),
                'in_progress' => $enrollments->whereNull('completed_at')->count(),
                'completed' => $enrollments->whereNotNull('completed_at')->count(),
                'learning_points' => (int) $enrollments->sum('total_points_earned'),
                'time_spent_minutes' => (int) ceil($enrollments->sum('total_time_spent') / 60),
            ],
            'continue_learning' => $enrollments
                ->filter(fn (UserContentEnrollment $enrollment): bool => $enrollment->content !== null && $enrollment->completed_at === null)
                ->take(4)
                ->map(fn (UserContentEnrollment $enrollment): array => $this->enrollmentPayload($enrollment))
                ->values(),
            'completed_content' => $enrollments
                ->filter(fn (UserContentEnrollment $enrollment): bool => $enrollment->content !== null && $enrollment->completed_at !== null)
                ->take(6)
                ->map(fn (UserContentEnrollment $enrollment): array => $this->enrollmentPayload($enrollment))
                ->values(),
            'achievements' => $user->achievements
                ->sortByDesc('earned_at')
                ->map(fn ($earned): array => [
                    'id' => $earned->achievement?->id,
                    'name' => $earned->achievement?->name,
                    'description' => $earned->achievement?->description,
                    'icon_url' => $earned->achievement?->icon_url,
                    'rarity' => $earned->achievement?->rarity,
                    'earned_at' => $earned->earned_at,
                ])->values(),
            'recent_points' => $user->pointTransactions()
                ->latest()
                ->limit(8)
                ->get(['id', 'points', 'transaction_type', 'description', 'created_at']),
        ]);
    }

    private function enrollmentPayload(UserContentEnrollment $enrollment): array
    {
        $content = $enrollment->content;

        return [
            'id' => $enrollment->id,
            'content' => [
                'id' => $content->id,
                'title' => $content->title,
                'slug' => $content->slug,
                'type' => $content->content_type,
                'description' => $content->description,
                'thumbnail_url' => $content->thumbnail_url,
                'difficulty_level' => $content->difficulty_level,
                'estimated_duration' => (int) $content->estimated_duration,
            ],
            'progress_percentage' => (float) $enrollment->progress_percentage,
            'points_earned' => (int) $enrollment->total_points_earned,
            'completed_at' => $enrollment->completed_at,
            'last_accessed_at' => $enrollment->last_accessed_at,
        ];
    }
}
