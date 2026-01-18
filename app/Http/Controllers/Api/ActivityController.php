<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\UserActivityAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;

class ActivityController extends Controller
{
    public function attempt(Request $request, $id)
    {
        try {
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

            // Check if user already has a successful attempt for this activity
            $existingSuccessfulAttempt = UserActivityAttempt::where('user_id', $user->id)
                ->where('activity_id', $activity->id)
                ->where('is_correct', true)
                ->first();

            $alreadyCompleted = (bool) $existingSuccessfulAttempt;
            $pointsAwarded = 0;

            // Only award points if this is the first successful attempt
            if ($request->is_correct && !$alreadyCompleted) {
                // Clamp awarded points to activity's max_points and non-negative
                $rawScore = (int) $request->score;
                $maxPoints = (int) ($activity->max_points ?? $rawScore);
                $pointsAwarded = max(0, min($rawScore, $maxPoints));

                // Update user's total score if column exists
                if (Schema::hasColumn('users', 'total_score')) {
                    $user->increment('total_score', $pointsAwarded);
                }
            }

            // Determine lesson_progress_id
            $lessonProgressId = null;
            $activitable = $activity->activitable;

            if ($activitable instanceof \App\Models\Lesson) {
                $lesson = $activitable;
                
                // Ensure enrollment exists
                 $enrollment = \App\Models\UserContentEnrollment::firstOrCreate(
                    ['user_id' => $user->id, 'content_id' => $lesson->content_id],
                    [
                        'enrolled_at' => now(), 
                        'started_at' => now(), 
                        'last_accessed_at' => now(),
                        'progress_percentage' => 0
                    ]
                );

                // Ensure lesson progress exists
                $lessonProgress = \App\Models\UserLessonProgress::firstOrCreate(
                    ['user_id' => $user->id, 'lesson_id' => $lesson->id],
                    ['enrollment_id' => $enrollment->id, 'status' => 'en_progreso', 'started_at' => now()]
                );
                $lessonProgressId = $lessonProgress->id;
            }

            if (!$lessonProgressId) {
                // Fallback or Error: Activity requires a lesson_progress_id constraint in DB.
                // If this activity is not part of a lesson, we might need to make the column nullable or handle differently.
                // For now, assuming all activities in this flow are lesson-based.
                 return response()->json(['error' => 'Activity must belong to a lesson to track progress.'], 422);
            }

            $attemptNumber = UserActivityAttempt::where('user_id', $user->id)
                ->where('activity_id', $activity->id)
                ->max('attempt_number') + 1;

            // Record the attempt
            $attempt = UserActivityAttempt::create([
                'user_id' => $user->id,
                'activity_id' => $activity->id,
                'lesson_progress_id' => $lessonProgressId,
                'attempt_number' => $attemptNumber,
                'is_correct' => $request->is_correct,
                'points_earned' => $pointsAwarded,
                'user_answers' => $request->answers,
                'completed_at' => now(),
            ]);

            // UPDATE PROGRESS STATISTICS
            if ($lessonProgressId) {
                $lessonProgress = \App\Models\UserLessonProgress::find($lessonProgressId);
                
                if ($lessonProgress && $lessonProgress->lesson) {
                    $lesson = $lessonProgress->lesson;
                    $lessonActivities = $lesson->activities;
                    
                    // 1. Calculate Lesson Stats
                    $totalActivities = $lessonActivities->count();
                    $pointsPossible = $lessonActivities->sum('max_points'); // Assuming max_points column on activities

                    // Get unique completed activities for this user and lesson
                    // Note: We use the just created attempt + historical data
                    $completedActivityIds = UserActivityAttempt::where('user_id', $user->id)
                        ->where('lesson_progress_id', $lessonProgressId) // Filter by progress to be safe, or by lesson activities
                        ->where('is_correct', true)
                        ->pluck('activity_id')
                        ->unique();
                    
                    $activitiesCompleted = $completedActivityIds->count();
                    
                    // Calculate actual points earned from completed activities (sum or their max_points)
                    $pointsEarned = $lessonActivities->whereIn('id', $completedActivityIds)->sum('max_points');

                    $lessonProgress->update([
                        'last_accessed_at' => now(),
                        'total_activities' => $totalActivities,
                        'activities_completed' => $activitiesCompleted,
                        'points_earned' => $pointsEarned,
                        'points_possible' => $pointsPossible,
                    ]);

                    // 2. Update Enrollment Stats
                    if ($lessonProgress->enrollment) {
                        $lessonProgress->enrollment->update([
                            'last_accessed_at' => now(),
                            'current_lesson_id' => $lesson->id,
                        ]);
                    }
                }
            }

            return response()->json([
                'attempt' => $attempt,
                'already_completed' => $alreadyCompleted,
                'points_awarded' => $pointsAwarded,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()], 500);
        }
    }
}
