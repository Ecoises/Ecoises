<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\UserActivityAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivityController extends Controller
{
    public function attempt(Request $request, $id)
    {
        $activity = Activity::findOrFail($id);
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'is_correct' => 'required|boolean',
            'score' => 'required|integer',
            'answers' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Record the attempt
        $attempt = UserActivityAttempt::create([
            'user_id' => $user->id,
            'activity_id' => $activity->id,
            'is_correct' => $request->is_correct,
            'score' => $request->score,
            'answers' => $request->answers,
            'attempted_at' => now(),
        ]);

        return response()->json($attempt);
    }
}
