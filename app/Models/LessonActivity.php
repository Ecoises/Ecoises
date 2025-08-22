<?php
// app/Models/LessonActivity.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonActivity extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'lesson_id',
        'title',
        'activity_order',
        'activity_type',
        'instructions',
        'content_data',
        'correct_answers',
        'hints',
        'max_points',
        'passing_score',
        'time_limit',
        'attempts_allowed',
        'success_message',
        'failure_message',
        'explanation',
        'is_mandatory',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'content_data' => 'array',
        'correct_answers' => 'array',
        'hints' => 'array',
        'is_mandatory' => 'boolean',
    ];
    
    // Relaciones
    
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
