<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lesson extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'course_id',
        'title',
        'description',
        'lesson_order',
        'lesson_type',
        'content_text',
        'media_url',
        'media_type',
        'audio_url',
        'audio_timestamps',
        'voice_id',
        'estimated_duration',
        'is_mandatory',
        'unlock_requirements',
        'is_published',
        'view_count',
        'completion_rate',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_mandatory' => 'boolean',
        'unlock_requirements' => 'array',
        'is_published' => 'boolean',
        'audio_timestamps' => 'array',
    ];
    
    // Relaciones
    
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
    
    public function activities(): HasMany
    {
        return $this->hasMany(LessonActivity::class);
    }
}