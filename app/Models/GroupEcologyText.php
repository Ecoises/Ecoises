<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Párrafo de importancia ecológica de un género o una familia (redactado con
 * IA). Es el respaldo de la ficha mientras la especie no tenga texto propio.
 */
class GroupEcologyText extends Model
{
    public const RANKS = ['genus', 'family'];

    protected $fillable = ['rank', 'name', 'text', 'sources', 'model', 'status', 'reviewed_by', 'reviewed_at', 'generated_at'];

    protected $casts = [
        'sources' => 'array',
        'reviewed_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isVisible(): bool
    {
        return $this->status !== SpeciesEcologyText::STATUS_REJECTED;
    }
}
