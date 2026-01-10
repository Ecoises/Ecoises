<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationalContent;
use Illuminate\Http\Request;

class EducationalContentController extends Controller
{
    protected $progressService;

    public function __construct(\App\Services\ContentProgressService $progressService)
    {
        $this->progressService = $progressService;
    }

    /**
     * Display a listing of the published educational contents.
     */
    public function index(Request $request)
    {
        $query = EducationalContent::where('status', 'published')
            ->where('is_published', true)
            ->with(['categories', 'author']); // Eager load necessary relationships

        // Search functionality
        if ($request->has('search')) {
            $searchTerm = $request->input('search');
            $query->where(function($q) use ($searchTerm) {
                $q->where('title', 'like', "%{$searchTerm}%")
                  ->orWhere('description', 'like', "%{$searchTerm}%");
            });
        }

        // Apply filters if needed (e.g. by category, level) - Keeping it simple for now as per plan
        if ($request->has('category')) {
             $query->whereHas('categories', function($q) use ($request) {
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
        $content = EducationalContent::where('slug', $slugOrId)
            ->orWhere('id', $slugOrId)
            ->with(['categories', 'lessons.activities', 'author', 'articleDetails', 'courseDetails'])
            ->firstOrFail();

        // If user is authenticated, attach enrollment and progress
        if (auth()->check()) {
            $user = auth()->user();
            
            $enrollment = $user->contentEnrollments()
                ->where('content_id', $content->id)
                ->first();
            
            if ($enrollment) {
                $content->enrollment = $enrollment;
                
                // Get lesson progress for this enrollment
                $lessonProgress = $enrollment->lessonProgress()
                    ->get()
                    ->keyBy('lesson_id');
                
                $content->lesson_progress = $lessonProgress;
            }
        }

        return response()->json($content);
    }

    /**
     * Iniciar el contenido (Inscripción).
     */
    public function start($slugOrId)
    {
        $user = auth()->user();
        
        // Try to find by slug first, then by ID
        $content = EducationalContent::where('slug', $slugOrId)
            ->orWhere('id', $slugOrId)
            ->firstOrFail();

        $enrollment = $this->progressService->startContent($user, $content);

        return response()->json($enrollment);
    }
}
