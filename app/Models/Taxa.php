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
        'is_introduced',
        'observation_count',
        'last_observed_at',
        // Trazabilidad local del inventario
        'taxon_author',
        'inventory_author',
        'local_records_count',
        'attribution',
        // Sincronización con APIs externas
        'last_synced_at',
        'sync_status',
        'sync_attempts',
        'gbif_taxon_key',
        'inat_taxon_id',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_native' => 'boolean',
        'is_endemic' => 'boolean',
        'is_introduced' => 'boolean',
        'last_observed_at' => 'datetime',
        'last_synced_at' => 'datetime',
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
    
    // Usamos la propiedad (colección eager loaded) en vez del método (query)
    // para aprovechar el eager loading del Service
    $ref = $this->apiReferences->firstWhere('api_source', 'inaturalist');
    if ($ref && $ref->data) {
        $apiData = $ref->data;
        $enriched = array_merge($enriched, [
            'rank' => $apiData['rank'] ?? null,
            'wikipedia_url' => $apiData['wikipedia_url'] ?? null,
            'wikipedia_summary' => $apiData['wikipedia_summary'] ?? null, // ✅ AGREGADO: Resumen
            'default_photo' => $apiData['default_photo'] ?? null,
            'observations_count_api' => $apiData['observations_count'] ?? 0,
            'ancestry_full' => $apiData['ancestry'] ?? null,
            'gallery' => $apiData['gallery'] ?? [],
            'ancestors' => $apiData['ancestors'] ?? [], // ✅ AGREGADO: Ancestros completos
            'conservation_status_details' => $apiData['conservation_status'] ?? null, // ✅ AGREGADO: Detalles de conservación
        ]);

        // Extraer status de establecimiento directamente de los datos de la API
        $service = app(\App\Services\TaxonService::class);
        $establishmentData = $service->extractEstablishmentStatusFromApiData($apiData);
        $enriched['is_native'] = $establishmentData['is_native'];
        $enriched['is_endemic'] = $establishmentData['is_endemic'];
        $enriched['is_introduced'] = $establishmentData['is_introduced'];
        $enriched['establishment_status_colombia'] = $establishmentData['status'];  // Para frontend
    }

    // Asegurar que los campos locales de trazabilidad siempre estén presentes
    $enriched['taxon_author']        = $enriched['taxon_author'] ?? null;
    $enriched['inventory_author']    = $enriched['inventory_author'] ?? null;
    $enriched['local_records_count'] = $enriched['local_records_count'] ?? 0;
    $enriched['attribution']         = $enriched['attribution'] ?? null;
    $enriched['inaturalist_id']      = $ref && is_numeric($ref->external_id) ? (int)$ref->external_id : ($ref->external_id ?? null);

    return $enriched;
}
}
