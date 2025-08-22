<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAchievement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'achievement_id',
        'earned_at',
        'progress_data',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'earned_at' => 'datetime',
        'progress_data' => 'array',
    ];
    
    // Relaciones
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }
}