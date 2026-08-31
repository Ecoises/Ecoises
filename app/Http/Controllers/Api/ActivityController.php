<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Services\ActivityAttemptService;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function __construct(private readonly ActivityAttemptService $attemptService) {}

    public function attempt(Request $request, int $id)
    {
        $validated = $request->validate([
            'answers' => ['required', 'array'],
            'time_taken' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);

        return response()->json($this->attemptService->attempt(
            $request->user(),
            Activity::findOrFail($id),
            $validated['answers'],
            $validated['time_taken'] ?? null,
        ));
    }
}
