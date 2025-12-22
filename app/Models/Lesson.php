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
        'status',
        'view_count',
        'completion_rate',
        'points',
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
        'status' => 'string',
        'estimated_duration' => 'integer',
        'audio_timestamps' => 'array',
    ];
    
    public static function estimateReadingTime(?string $text): int
    {
        if (!$text) return 0;
        // Limpiar HTML y contar palabras
        $words = str_word_count(strip_tags($text));
        $wpm = 200; // Palabras por minuto promedio
        return max(1, (int) ceil(($words / $wpm) * 60));
    }

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