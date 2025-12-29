<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_id', // Renamed from course_id
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
        'status',
        'view_count',
        'completion_rate',
        'points',
        'references',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
        'unlock_requirements' => 'array',
        'is_published' => 'boolean',
        'audio_timestamps' => 'array',
        'is_published' => 'boolean',
        'audio_timestamps' => 'array',
        'references' => 'array',
    ];

    public static function estimateReadingTime(?string $text): int
    {
        if (!$text) return 0;
        $words = str_word_count(strip_tags($text));
        $wpm = 200;
        return max(1, (int) ceil(($words / $wpm) * 60));
    }

    // Relationships
    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'content_id');
    }
    
    // Convenience for backward compatibility or clarity if guaranteed to be a course
    public function course(): BelongsTo
    {
         return $this->belongsTo(Course::class, 'content_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activitable');
    }
}