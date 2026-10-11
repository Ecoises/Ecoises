<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationalContent;
use App\Services\Learning\ActivityEvaluationService;
use App\Services\Learning\ContentProgressService;
use Illuminate\Http\Request;

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
     * GET /educational-contents — catálogo publicado, paginado y liviano.
     *
     * Devuelve solo los campos de la tarjeta (sin cuerpo de lecciones ni datos
     * del autor más allá de nombre y avatar) y, en `meta.facets`, cuántos
     * contenidos hay por tipo, nivel y categoría, para que la interfaz solo
     * muestre filtros que tienen resultados.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => 'sometimes|nullable|string|max:120',
            'search' => 'sometimes|nullable|string|max:120', // nombre antiguo
            'type' => 'sometimes|nullable|in:course,article,resource',
            'level' => 'sometimes|nullable|in:principiante,intermedio,avanzado',
            'category' => 'sometimes|nullable|string|max:120',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:6|max:48',
        ]);

        $search = trim((string) ($validated['q'] ?? $validated['search'] ?? ''));

        $query = EducationalContent::published()
            ->select(['id', 'title', 'slug', 'description', 'thumbnail_url', 'content_type', 'difficulty_level', 'estimated_duration', 'author_id', 'rating_average', 'rating_count', 'view_count', 'is_featured', 'published_at'])
            ->with(['categories:id,name,slug', 'author:id,full_name,avatar'])
            ->withCount(['lessons' => fn ($lessons) => $lessons->where('is_published', true)])
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($validated['type'] ?? null, fn ($q, $type) => $q->where('content_type', $type))
            ->when($validated['level'] ?? null, fn ($q, $level) => $q->where('difficulty_level', $level))
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->whereHas('categories', fn ($c) => $c->where('slug', $category)->orWhere('name', $category)))
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at');

        $page = $query->paginate((int) ($validated['per_page'] ?? 24));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'pagination' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
                'facets' => $this->facets(),
            ],
        ]);
    }

    /**
     * Conteos del catálogo publicado (sin filtros), en caché 5 minutos.
     */
    private function facets(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('learn:facets:v1', 300, function () {
            $published = fn () => EducationalContent::published();

            return [
                'total' => $published()->count(),
                'types' => $published()->selectRaw('content_type, count(*) as total')->groupBy('content_type')->pluck('total', 'content_type'),
                'levels' => $published()->selectRaw('difficulty_level, count(*) as total')->groupBy('difficulty_level')->pluck('total', 'difficulty_level'),
                'categories' => \App\Models\ContentCategory::query()
                    ->select(['id', 'name', 'slug'])
                    ->whereHas('content', fn ($q) => $q->published())
                    ->withCount(['content as total' => fn ($q) => $q->published()])
                    ->orderBy('name')
                    ->get(),
            ];
        });
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

        // Sesión opcional: la ruta es pública, pero si llega un token se añade el progreso.
        $user = request()->user('sanctum');

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
