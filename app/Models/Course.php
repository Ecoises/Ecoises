<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING = 'pending';
    const STATUS_REVIEWED = 'reviewed';
    const STATUS_PUBLISHED = 'published';

    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_REVIEWED => 'Revisado',
            self::STATUS_PUBLISHED => 'Publicado',
        ];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'type',
        'description',
        'thumbnail_url',
        'difficulty_level',
        'category_id',
        'tags',
        'estimated_duration',
        'completion_points',
        'achievement_id',
        'related_taxa',
        'target_location_ids',
        'author_id',
        'is_published',
        'status',
        'content_text',
        'voice_id',
        'audio_url',
        'audio_timestamps',
        'enrollment_count',
        'completion_rate',
        'rating_average',
        'rating_count',
        'references',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'related_taxa' => 'array',
        'target_location_ids' => 'array',
        'is_published' => 'boolean',
        'estimated_duration' => 'integer',
        'status' => 'string',
        'audio_timestamps' => 'array',
        'references' => 'array',
        'tags' => 'array',
    ];
    
    // Relaciones
    
    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class, 'achievement_id');
    }
    
    public function refreshDuration(): void
    {
        if ($this->type === 'modular') {
            $this->estimated_duration = $this->lessons()->sum('estimated_duration');
        } else {
            // Para lecciones simples, la duración depende de su propio contenido o audio
            // Si hay audio, se prefiere la duración del audio (ya guardada en estimated_duration por el formulario)
            // Si no hay duración guardada, se podría calcular de nuevo, pero el formulario ya lo hace.
        }
        $this->save();
    }
    
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }
    
    public function enrollments(): HasMany
    {
        return $this->hasMany(UserCourseEnrollment::class);
    }
}