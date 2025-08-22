<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserResourceInteraction extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'resource_id',
        'first_viewed_at',
        'last_viewed_at',
        'view_count',
        'time_spent',
        'is_completed',
        'completed_at',
        'is_bookmarked',
        'is_liked',
        'is_downloaded',
        'progress_percentage',
        'last_position',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'first_viewed_at' => 'datetime',
        'last_viewed_at' => 'datetime',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'is_bookmarked' => 'boolean',
        'is_liked' => 'boolean',
        'is_downloaded' => 'boolean',
    ];
    
    // Relaciones
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function resource(): BelongsTo
    {
        return $this->belongsTo(EducationalResource::class, 'resource_id');
    }
}