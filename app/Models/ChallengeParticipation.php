<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChallengeParticipation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'challenge_id',
        'progress_value',
        'completed',
        'completed_at',
        'joined_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'completed' => 'boolean',
        'completed_at' => 'datetime',
        'joined_at' => 'datetime',
    ];
    
    // Relaciones
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }
}
