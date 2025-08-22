<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Observation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'taxon_id',
        'latitude',
        'longitude',
        'location_accuracy',
        'location_description',
        'observed_at',
        'description',
        'notes',
        'identification_status',
        'confidence_level',
        'quality_score',
        'points_awarded',
        'is_featured',
        'is_public',
        'is_research_grade',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'observed_at' => 'datetime',
        'is_featured' => 'boolean',
        'is_public' => 'boolean',
        'is_research_grade' => 'boolean',
    ];

    // Relaciones

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxa::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ObservationPhoto::class);
    }
    
    public function identifications(): HasMany
    {
        return $this->hasMany(Identification::class);
    }
    
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
    
    public function likes(): HasMany
    {
        return $this->hasMany(ObservationLike::class);
    }
}