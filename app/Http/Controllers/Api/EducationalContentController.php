<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EducationalContent;
use Illuminate\Http\Request;

class EducationalContentController extends Controller
{
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
    public function show($id)
    {
        $content = EducationalContent::where('status', 'published')
            ->where('is_published', true)
            ->with(['categories', 'lessons', 'author', 'articleDetails', 'courseDetails'])
            ->findOrFail($id);

        return response()->json($content);
    }
}
