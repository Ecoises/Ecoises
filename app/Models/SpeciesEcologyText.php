<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Párrafo de importancia ecológica de una especie (redactado con IA a partir
 * de datos verificados). published = visible con etiqueta de IA;
 * reviewed = validado por una persona; rejected = oculto.
 */
class SpeciesEcologyText extends Model
{
    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = ['taxon_id', 'text', 'sources', 'model', 'status', 'reviewed_by', 'reviewed_at', 'generated_at'];

    protected $casts = [
        'sources' => 'array',
        'reviewed_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxa::class, 'taxon_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isVisible(): bool
    {
        return $this->status !== self::STATUS_REJECTED;
    }
}
