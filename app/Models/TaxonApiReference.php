<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxonApiReference extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'taxon_id',
        'api_source',
        'external_id',
        'api_url',
        'confidence_score',
        'is_primary',
        'last_verified_at',
        'data',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_primary' => 'boolean',
        'last_verified_at' => 'datetime',
        'data' => 'array',
    ];

    // Relaciones

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxa::class);
    }
}
