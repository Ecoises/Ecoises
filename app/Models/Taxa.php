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

    /**
     * Accessor para datos enriquecidos (une essentials + API)
     */
   public function getEnrichedDataAttribute(): array
{
    $enriched = $this->toArray();

    $ref = $this->apiReferences()->first();
    if ($ref && $ref->data) {
        $apiData = $ref->data;
        $enriched = array_merge($enriched, [
            'rank' => $apiData['rank'] ?? null,
            'wikipedia_url' => $apiData['wikipedia_url'] ?? null,
            'default_photo' => $apiData['default_photo'] ?? null,
            'observations_count_api' => $apiData['observations_count'] ?? 0,
            'ancestry_full' => $apiData['ancestry'] ?? null,
        ]);

        // Extraer status de establecimiento directamente de los datos de la API
        $service = app(\App\Services\TaxonService::class);
        $establishmentData = $service->extractEstablishmentStatusFromApiData($apiData);
        $enriched['is_native'] = $establishmentData['is_native'];
        $enriched['is_endemic'] = $establishmentData['is_endemic'];
        $enriched['establishment_status_colombia'] = $establishmentData['status'];  // Para frontend
    }

    return $enriched;
}
}
