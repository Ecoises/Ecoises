<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Course extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'description',
        'thumbnail_url',
        'difficulty_level',
        'category',
        'estimated_duration',
        'completion_points',
        'achievement_id',
        'related_taxa',
        'target_location_ids',
        'author_id',
        'is_published',
        'enrollment_count',
        'completion_rate',
        'rating_average',
        'rating_count',
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
    ];
    
    // Relaciones
    
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
    
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class, 'achievement_id');
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