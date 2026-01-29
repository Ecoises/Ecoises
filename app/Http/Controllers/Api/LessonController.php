<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\ContentProgressService;
use Illuminate\Http\Request;
use Exception;

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
        $lesson = Lesson::where('slug', $slugOrId)
            ->orWhere('id', $slugOrId)
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
