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
        'slug',
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
        'audio_timestamps' => 'array',
        'is_published' => 'boolean',
        'references' => 'array',
    ];

    public static function estimateReadingTime(?string $text): int
    {
        if (! $text) {
            return 0;
        }
        $words = str_word_count(strip_tags($text));
        $wpm = 200;

        return max(1, (int) ceil(($words / $wpm) * 60));
    }

    protected static function booted()
    {
        // Calcular duración estimada antes de guardar
        static::saving(function ($lesson) {
            // Si el contenido de texto cambió, recalcular la duración
            if ($lesson->isDirty('content_text')) {
                $plainText = strip_tags($lesson->content_text ?? '');
                $words = str_word_count($plainText);
                // 200 palabras por minuto es el estándar de lectura
                $minutes = (int) ceil($words / 200);
                $lesson->estimated_duration = max(1, $minutes);
            }
        });

        // Al guardar la lección, avisa al Contenido Educativo (Padre)
        // para que sume el tiempo de todas sus lecciones.
        static::saved(function ($lesson) {
            $lesson->syncParentDuration();
        });

        // También al borrar, para que el tiempo del padre baje
        static::deleted(function ($lesson) {
            $lesson->syncParentDuration();
        });
    }

    public function syncParentDuration()
    {
        // Accedemos a la relación 'content' (EducationalContent)
        if ($this->content) {
            $totalMinutes = $this->content->lessons()->sum('estimated_duration');

            $this->content->update([
                'estimated_duration' => $totalMinutes,
            ]);
        }
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
        return $this->morphMany(Activity::class, 'activitable')->orderBy('activity_order');
    }
}
