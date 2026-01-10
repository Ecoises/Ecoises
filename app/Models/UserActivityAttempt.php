<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserActivityAttempt extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'activity_id',
        'lesson_progress_id',
        'attempt_number',
        'started_at',
        'completed_at',
        'user_answers',
        'is_correct',
        'points_earned',
        'time_taken',
        'hints_used',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'user_answers' => 'array',
        'is_correct' => 'boolean',
        'hints_used' => 'array',
    ];

    // Relaciones
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    public function lessonProgress(): BelongsTo
    {
        return $this->belongsTo(UserLessonProgress::class, 'lesson_progress_id');
    }
}
