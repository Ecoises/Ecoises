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
        'location_name',
        'observed_at',
        'description',
        'notes',
        'is_public',
        'points_awarded',
        'challenge_id',
        'verification_status',
        'reviewed_by',
        'reviewed_at',
        'review_note',
    ];

    // Estado de verificación cuando el avistamiento es evidencia de un reto
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'observed_at' => 'datetime',
        'is_public'   => 'boolean',
        'latitude'    => 'float',
        'longitude'   => 'float',
        'reviewed_at' => 'datetime',
    ];

    // ── Relaciones ───────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxa::class, 'taxon_id');
    }

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(Challenge::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ObservationPhoto::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function likes(): HasMany
    {
        return $this->hasMany(ObservationLike::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}