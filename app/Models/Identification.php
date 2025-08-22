<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Identification extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'observation_id',
        'user_id',
        'taxon_id',
        'confidence',
        'reasoning',
        'is_automatic',
        'ai_confidence',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_automatic' => 'boolean',
    ];

    // Relaciones

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxa::class);
    }
}