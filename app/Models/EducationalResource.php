<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EducationalResource extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'title',
        'resource_type',
        'content_text',
        'media_url',
        'thumbnail_url',
        'difficulty_level',
        'category',
        'tags',
        'estimated_duration',
        'related_taxa',
        'related_courses',
        'prerequisite_courses',
        'author_id',
        'is_published',
        'is_featured',
        'view_count',
        'like_count',
        'download_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tags' => 'array',
        'related_taxa' => 'array',
        'related_courses' => 'array',
        'prerequisite_courses' => 'array',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
    ];

    // Relaciones
    
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(UserResourceInteraction::class, 'resource_id');
    }
}