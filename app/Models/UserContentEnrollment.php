<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserContentEnrollment extends Model
{
    use HasFactory;

    protected $table = 'user_content_enrollments';

    protected $fillable = [
        'user_id',
        'content_id',
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

    protected $casts = [
        'enrolled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'progress_percentage' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'content_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'content_id');
    }

    public function currentLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'current_lesson_id');
    }
    
    public function lessonProgress(): HasMany
    {
        return $this->hasMany(UserLessonProgress::class, 'enrollment_id');
    }
    
    public function articleProgress(): HasMany // Singular usually, but table is progress
    {
        // One progress record per article per user? 
        // Logic: user_article_progress links to enrollment_id
        return $this->hasMany(UserArticleProgress::class, 'enrollment_id');
    }
}
