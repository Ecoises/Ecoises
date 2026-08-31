<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationalContent;
use App\Services\ActivityEvaluationService;
use App\Services\ContentProgressService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class EducationalContentController extends Controller
{
    protected $progressService;

    public function __construct(
        ContentProgressService $progressService,
        private readonly ActivityEvaluationService $activityEvaluationService,
    ) {
        $this->progressService = $progressService;
    }

    /**
     * Display a listing of the published educational contents.
     */
    public function index(Request $request)
    {
        $query = EducationalContent::published()
            ->with(['categories', 'author']); // Eager load necessary relationships

        // Search functionality
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                    ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // Apply filters if needed (e.g. by category, level) - Keeping it simple for now as per plan
        if ($request->has('category')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('name', $request->input('category'));
            });
        }

        $contents = $query->orderBy('created_at', 'desc')->get();

        return response()->json($contents);
    }

    /**
     * Display the specified resource.
     */
    public function show($slugOrId)
    {
        // Try to find by slug first, then by ID for backwards compatibility
        $content = EducationalContent::published()
            ->where(function ($query) use ($slugOrId) {
                $query->where('slug', $slugOrId)
                    ->orWhere('id', $slugOrId);
            })
            ->with([
                'categories',
                'lessons' => fn ($query) => $query
                    ->where('status', EducationalContent::STATUS_PUBLISHED)
                    ->where('is_published', true)
                    ->with('activities'),
                'author',
                'articleDetails',
                'courseDetails',
                'assets',
            ])
            ->firstOrFail();

        // Resolve authenticated user (optional): prefer request()->user(), then fallback to Sanctum token if available
        $user = request()->user();
        if (! $user) {
            try {
                $bearer = request()->bearerToken();
                if ($bearer && class_exists(\Laravel\Sanctum\PersonalAccessToken::class) && \Illuminate\Support\Facades\Schema::hasTable('personal_access_tokens')) {
                    $accessToken = PersonalAccessToken::findToken($bearer);
                    if ($accessToken && $accessToken->tokenable) {
                        $user = $accessToken->tokenable;
                    }
                }
            } catch (\Throwable $e) {
                // Optional auth must never break public show; ignore errors
            }
        }

        // If user is authenticated, attach enrollment and progress
        if ($user) {
            // Avoid relying on a missing User::contentEnrollments() relation
            $enrollment = \App\Models\UserContentEnrollment::where('user_id', $user->id)
                ->where('content_id', $content->id)
                ->first();

            if ($enrollment) {
                $content->enrollment = $enrollment;

                // Get lesson progress for this enrollment
                $lessonProgress = $enrollment->lessonProgress()
                    ->get()
                    ->keyBy('lesson_id');

                $content->lesson_progress = $lessonProgress;

                if ($content->isArticle()) {
                    $content->article_progress = $enrollment->articleProgress()->first();
                }
            }

            // Get user's activity attempts with points (guard against missing lessons)
            $lessons = $content->lessons ?? collect();
            $activityIds = $lessons->pluck('activities')->flatten()->pluck('id');

            // Get all attempts for this user and activities
            $activityAttempts = \App\Models\UserActivityAttempt::where('user_id', $user->id)
                ->whereIn('activity_id', $activityIds)
                ->get()
                ->keyBy('activity_id');

            // Build activity progress array
            $activityProgress = [];
            foreach ($activityIds as $activityId) {
                $attempt = $activityAttempts->get($activityId);
                $activityProgress[] = [
                    'id' => $activityId,
                    'is_completed' => $attempt && $attempt->is_correct,
                    'points_earned' => $attempt ? $attempt->points_earned : 0,
                ];
            }

            $content->activity_progress = $activityProgress;

            // Keep backward compatibility
            $completedActivities = $activityAttempts->where('is_correct', true)->keys()->toArray();
            $content->completed_activities = $completedActivities;
        }

        // Nunca se envían las respuestas correctas al navegador. Cada tipo de
        // actividad recibe únicamente los datos necesarios para ser presentado.
        foreach ($content->lessons as $lesson) {
            $lesson->setRelation(
                'activities',
                $lesson->activities->map(
                    fn ($activity): array => $this->activityEvaluationService->publicPayload($activity)
                ),
            );
        }

        return response()->json($content);
    }

    /**
     * Iniciar el contenido (Inscripción).
     */
    public function start(Request $request, $slugOrId)
    {
        $user = $request->user();

        // Try to find by slug first, then by ID
        $content = EducationalContent::published()
            ->where(function ($query) use ($slugOrId) {
                $query->where('slug', $slugOrId)
                    ->orWhere('id', $slugOrId);
            })
            ->firstOrFail();

        $enrollment = $this->progressService->startContent($user, $content);

        return response()->json($enrollment);
    }

    public function updateArticleProgress(Request $request, $slugOrId)
    {
        $validated = $request->validate([
            'reading_progress' => ['required', 'numeric', 'min:0', 'max:100'],
            'last_position' => ['nullable', 'integer', 'min:0'],
            'time_spent' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);
        $content = EducationalContent::published()
            ->where('content_type', EducationalContent::TYPE_ARTICLE)
            ->where(function ($query) use ($slugOrId) {
                $query->where('slug', $slugOrId)
                    ->orWhere('id', $slugOrId);
            })
            ->firstOrFail();

        return response()->json($this->progressService->updateArticleProgress(
            $request->user(),
            $content,
            (float) $validated['reading_progress'],
            $validated['last_position'] ?? null,
            $validated['time_spent'] ?? 0,
        ));
    }
}
