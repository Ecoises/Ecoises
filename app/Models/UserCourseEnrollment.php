<?php
// app/Models/UserCourseEnrollment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserCourseEnrollment extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'course_id',
        'enrolled_at',
        'started_at',
        'completed_at',
        'last_accessed_at',
        'current_lesson_id',
        'lessons_completed',
        'total_lessons',
        'progress_percentage',
        'total_points_earned',
        'total_points_possible',
        'final_score',
        'total_time_spent',
        'user_rating',
        'user_feedback',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'enrolled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];
    
    // Relaciones
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
    
    public function currentLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'current_lesson_id');
    }
    
    public function lessonProgresses(): HasMany
    {
        return $this->hasMany(UserLessonProgress::class, 'enrollment_id');
    }
}