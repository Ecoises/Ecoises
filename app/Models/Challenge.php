<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Challenge extends Model
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
        'challenge_type',
        'start_date',
        'end_date',
        'target_value',
        'reward_points',
        'reward_badge_id',
        'criteria',
        'is_active',
        'participant_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'criteria' => 'array',
        'is_active' => 'boolean',
    ];
    
    // Relaciones
    
    public function rewardBadge(): BelongsTo
    {
        return $this->belongsTo(Achievement::class, 'reward_badge_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ChallengeParticipation::class);
    }
}