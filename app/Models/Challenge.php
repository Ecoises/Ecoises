<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reto de campo: "observa esta especie (target_value veces) y gana reward_points".
 * La evidencia son avistamientos (observations.challenge_id).
 */
class Challenge extends Model
{
    use HasFactory;

    public const TYPE_OBSERVATION = 'observation';

    protected $fillable = [
        'title',
        'description',
        'challenge_type',
        'taxon_id',
        'start_date',
        'end_date',
        'target_value',
        'requires_photo',
        'reward_points',
        'reward_badge_id',
        'criteria',
        'is_active',
        'participant_count',
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'criteria' => 'array',
        'is_active' => 'boolean',
        'requires_photo' => 'boolean',
        'target_value' => 'integer',
        'reward_points' => 'integer',
    ];

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxa::class, 'taxon_id');
    }

    public function rewardBadge(): BelongsTo
    {
        return $this->belongsTo(Achievement::class, 'reward_badge_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(ChallengeParticipation::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    /**
     * Retos activos y dentro de su ventana de fechas.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhere('start_date', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()));
    }

    public function isAvailable(): bool
    {
        return $this->is_active
            && (! $this->start_date || $this->start_date->lte(now()))
            && (! $this->end_date || $this->end_date->gte(now()));
    }

    public function requiredObservations(): int
    {
        return max(1, (int) ($this->target_value ?? 1));
    }
}
