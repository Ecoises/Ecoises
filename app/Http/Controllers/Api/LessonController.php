<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationalContent;
use App\Models\Lesson;
use App\Services\ContentProgressService;
use Exception;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    protected $progressService;

    public function __construct(ContentProgressService $progressService)
    {
        $this->progressService = $progressService;
    }

    /**
     * Mark a lesson as completed.
     */
    public function complete(Request $request, $slugOrId)
    {
        // Try to find by slug first, then by ID for backwards compatibility
        $lesson = Lesson::query()
            ->where(function ($query) use ($slugOrId) {
                $query->where('slug', $slugOrId)
                    ->orWhere('id', $slugOrId);
            })
            ->where('status', EducationalContent::STATUS_PUBLISHED)
            ->where('is_published', true)
            ->whereHas('content', fn ($query) => $query->published())
            ->firstOrFail();
        $user = $request->user();

        try {
            $progress = $this->progressService->completeLesson($user, $lesson);

            return response()->json($progress);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400); // Bad Request if prerequisites not met
        }
    }
}
