<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Taxa extends Model
{
    use HasFactory;

    protected $table = 'taxa';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'scientific_name',
        'common_name',
        'kingdom',
        'phylum',
        'class',
        'order_name',
        'family',
        'genus',
        'species',
        'conservation_status',
        'is_native',
        'is_endemic',
        'observation_count',
        'identification_count',
        'last_observed_at',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_native' => 'boolean',
        'is_endemic' => 'boolean',
        'last_observed_at' => 'datetime',
    ];

    // Relaciones
    
    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class, 'taxon_id');
    }
    
    public function apiReferences(): HasMany
    {
        return $this->hasMany(TaxonApiReference::class, 'taxon_id');
    }
    
    public function identifications(): HasMany
    {
        return $this->hasMany(Identification::class, 'taxon_id');
    }
}
