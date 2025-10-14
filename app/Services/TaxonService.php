<?php

namespace App\Services;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Models\UnifiedApiCache;
use App\Services\Api\INaturalistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaxonService
{
    /**
     * @var INaturalistService
     */
    protected $iNaturalistService;
    
    /**
     * @param INaturalistService $iNaturalistService
     */
    public function __construct(INaturalistService $iNaturalistService)
    {
        $this->iNaturalistService = $iNaturalistService;
    }
    
    /**
     * Obtiene todos los taxones con paginación
     *
     * @param array $filters
     * @return array
     */
    public function getAllTaxa(array $filters = []): array
    {
        try {
            // Obtener parámetros de paginación
            $perPage = $filters['per_page'] ?? 12;
            $page = $filters['page'] ?? 1;
            
            // Consulta base
            $query = Taxa::query();
            
            // Aplicar filtros adicionales si existen
            if (isset($filters['rank'])) {
                $query->where('rank', $filters['rank']);
            }
            
            // Obtener resultados paginados
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            
            // Formatear resultados
            $taxa = $paginator->items();
            $formattedTaxa = [];
            
            foreach ($taxa as $taxon) {
                $formattedTaxa[] = $this->formatTaxonForResponse($taxon);
            }
            
            return [
                'success' => true,
                'data' => $formattedTaxa,
                'meta' => [
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'from' => $paginator->firstItem(),
                        'to' => $paginator->lastItem(),
                    ]
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('Error al obtener todos los taxones: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => [
                    'message' => 'Error al obtener la lista de taxones',
                    'details' => $e->getMessage()
                ]
            ];
        }
    }
    
    /**
     * Busca taxones por nombre científico o común
     *
     * @param string $query
     * @param array $filters
     * @return array
     */
     // Agrega si no está (para collect())

    public function searchTaxa(string $query, array $filters = []): array
    {
        // Si la consulta está vacía, buscamos especies populares o todas las especies
        if (empty(trim($query))) {
            // Buscar especies populares ordenadas por número de observaciones
            $apiResult = $this->iNaturalistService->searchTaxon('', array_merge($filters, [
                'order_by' => 'observations_count',
                'order' => 'desc',
                'has' => ['photos'], // Solo especies con fotos
                'rank' => 'species', // Solo especies (no géneros, familias, etc.)
                'is_active' => 'true', // Solo especies activas
                'per_page' => $filters['per_page'] ?? 15,
            ]));
            
            if (!$apiResult['success']) {
                Log::error('Error al obtener especies populares', [
                    'filters' => $filters,
                    'error' => $apiResult['error'] ?? 'Error desconocido'
                ]);
                
                return [
                    'success' => false,
                    'error' => $apiResult['error'] ?? ['message' => 'Error al obtener especies populares'],
                    'source' => 'api',
                ];
            }
            
            // Procesar y guardar los resultados
            $savedTaxa = [];
            if (isset($apiResult['data']) && is_array($apiResult['data'])) {
                foreach ($apiResult['data'] as $taxonData) {
                    $savedTaxon = $this->createOrUpdateTaxonFromApiData($taxonData);
                    if ($savedTaxon) {
                        $savedTaxa[] = $savedTaxon;
                    }
                }
            }
            
            // NUEVO: Enriquecer con datos unidos
            $formattedTaxa = collect($savedTaxa)->map->enriched_data->toArray();
            
            return [
                'success' => true,
                'data' => $formattedTaxa,
                'pagination' => $apiResult['pagination'] ?? [
                    'total' => count($formattedTaxa),
                    'per_page' => $filters['per_page'] ?? 15,
                    'current_page' => $filters['page'] ?? 1,
                    'last_page' => 1,
                ],
                'cached' => $apiResult['cached'] ?? false,
                'source' => 'api',
            ];
        }

        // Primero intentamos buscar en la base de datos local
        $localResults = $this->searchLocalTaxa($query, $filters);
        
        // Si hay resultados locales, los enriquecemos y devolvemos
        if (!empty($localResults['data'])) {
            // NUEVO: Enriquecer locales (asumiendo que son Taxa models o paginador)
            $formattedLocal = collect($localResults['data'])->map(function ($taxon) {
                return $taxon instanceof Taxa ? $taxon->enriched_data : $taxon;
            })->toArray();
            
            $localResults['data'] = $formattedLocal;  // Actualiza el array
            $localResults['source'] = 'local';
            return $localResults;
        }
        
        // Si no hay resultados locales, buscamos en la API de iNaturalist
        try {
            $apiResults = $this->iNaturalistService->searchTaxon($query, $filters);
            
            if (!$apiResults['success']) {
                Log::error('Error al buscar taxones en iNaturalist', [
                    'query' => $query,
                    'filters' => $filters,
                    'error' => $apiResults['error'] ?? 'Error desconocido'
                ]);
                
                return [
                    'success' => false,
                    'error' => $apiResults['error'] ?? ['message' => 'Error al buscar taxones en la API'],
                    'source' => 'api',
                ];
            }
            
            // Procesamos y guardamos los resultados de la API
            $savedTaxa = [];
            
            if (isset($apiResults['data']) && is_array($apiResults['data'])) {
                foreach ($apiResults['data'] as $taxonData) {
                    $savedTaxon = $this->createOrUpdateTaxonFromApiData($taxonData);
                    if ($savedTaxon) {
                        $savedTaxa[] = $savedTaxon;
                    }
                }
            }
            
            // NUEVO: Enriquecer con datos unidos
            $formattedTaxa = collect($savedTaxa)->map->enriched_data->toArray();
            
            return [
                'success' => true,
                'data' => $formattedTaxa,
                'pagination' => $apiResults['pagination'] ?? [
                    'total' => count($formattedTaxa),
                    'per_page' => $filters['per_page'] ?? 15,
                    'current_page' => $filters['page'] ?? 1,
                    'last_page' => 1,
                ],
                'cached' => $apiResults['cached'] ?? false,
                'source' => 'api',
            ];
            
        } catch (\Exception $e) {
            Log::error('Excepción al buscar taxones: ' . $e->getMessage(), [
                'query' => $query,
                'filters' => $filters,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => ['message' => 'Error inesperado al buscar taxones: ' . $e->getMessage()],
                'source' => 'api',
            ];
        }
    }
        
    /**
     * Busca taxones en la base de datos local
     *
     * @param string $query
     * @param array $filters
     * @return array
     */
    protected function searchLocalTaxa(string $query, array $filters = []): array
    {
        $queryBuilder = Taxa::query();
        
        // Búsqueda por nombre científico o común
        $queryBuilder->where(function($q) use ($query) {
            $q->where('scientific_name', 'like', "%{$query}%")
              ->orWhere('common_name', 'like', "%{$query}%");
        });
        
        // Aplicar filtros
        if (isset($filters['rank'])) {
            $queryBuilder->where('rank', $filters['rank']);
        }
        
        // Ordenar por número de observaciones (por defecto)
        $orderBy = $filters['order_by'] ?? 'observation_count';
        $order = $filters['order'] ?? 'desc';
        $queryBuilder->orderBy($orderBy, $order);
        
        // Paginación
        $perPage = $filters['per_page'] ?? 15;
        $results = $queryBuilder->paginate($perPage);
        
        return [
            'success' => true,
            'data' => $results->items(),
            'pagination' => [
                'total' => $results->total(),
                'per_page' => $results->perPage(),
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
            ]
        ];
    }
    
    /**
     * Obtiene un taxón por su ID, primero de la base de datos local y si no existe, de la API
     *
     * @param int $id
     * @param bool $forceRefresh Si es true, fuerza la actualización desde la API
     * @return array
     */
    public function getTaxonById(int $id, bool $forceRefresh = false): array
    {
        // Si no se fuerza la actualización, buscamos primero en la base de datos local
        if (!$forceRefresh) {
            $localTaxon = Taxa::with(['apiReferences', 'observations'])->find($id);
            
            if ($localTaxon) {
                return [
                    'success' => true,
                    'data' => $localTaxon,
                    'source' => 'local',
                ];
            }
        }
        
        // Si no se encontró localmente o se forzó la actualización, buscamos en la API
        $apiResult = $this->iNaturalistService->getTaxonById($id);
        
        if (!$apiResult['success']) {
            return [
                'success' => false,
                'error' => $apiResult['error'] ?? ['message' => 'Error al obtener el taxón de la API'],
                'source' => 'api',
            ];
        }
        
        // Procesamos y guardamos el taxón de la API
        $savedTaxon = $this->createOrUpdateTaxonFromApiData($apiResult['data']);
        
        if (!$savedTaxon) {
            return [
                'success' => false,
                'error' => ['message' => 'Error al guardar el taxón en la base de datos'],
                'source' => 'api',
            ];
        }
        
        return [
            'success' => true,
            'data' => $savedTaxon,
            'cached' => $apiResult['cached'] ?? false,
            'source' => 'api',
        ];
    }
    
    /**
     * Obtiene las observaciones de un taxón
     *
     * @param int $taxonId
     * @param array $params
     * @return array
     */
    /**
     * Obtiene observaciones de un taxón específico
     *
     * @param int $taxonId
     * @param array $params
     * @return array
     */
    public function getTaxonObservations(int $taxonId, array $params = []): array
    {
        // Primero obtenemos el taxón para asegurarnos de que existe
        $taxonResult = $this->getTaxonById($taxonId);
        
        if (!$taxonResult['success']) {
            return $taxonResult;
        }
        
        // Obtenemos las observaciones de la API
        $apiResult = $this->iNaturalistService->getTaxonObservations($taxonId, $params);
        
        if (!$apiResult['success']) {
            return [
                'success' => false,
                'error' => $apiResult['error'] ?? ['message' => 'Error al obtener las observaciones del taxón'],
                'source' => 'api',
            ];
        }
        
        // Aquí podríamos procesar y guardar las observaciones en la base de datos local
        // si fuera necesario para nuestra aplicación
        
        return [
            'success' => true,
            'data' => $apiResult['data'],
            'pagination' => $apiResult['pagination'] ?? [],
            'cached' => $apiResult['cached'] ?? false,
            'source' => 'api',
        ];
    }
    
    /**
     * Obtiene especies cercanas a una ubicación específica
     *
     * @param array $params
     * @return array
     */
    public function getPlaceObservations(array $params = []): array
    {
        // Obtener la ubicación de prueba desde la base de datos
        $location = \App\Models\Location::where('is_active', true)->first();
        
        if (!$location) {
            return [
                'success' => false,
                'error' => ['message' => 'No se encontró una ubicación activa en la base de datos'],
                'source' => 'local',
            ];
        }
        
        // Configurar parámetros para la búsqueda de especies por ubicación
        $defaultParams = [
            'per_page' => $params['per_page'] ?? 30,
            'page' => $params['page'] ?? 1,
            'order_by' => $params['order_by'] ?? 'observations_count',
            'order' => $params['order'] ?? 'desc',
            'photos' => 'true',
            'taxon_geoprivacy' => 'open', // Solo especies con ubicación abierta
            'lat' => $location->latitude,
            'lng' => $location->longitude,
            'radius' => $location->radius_km, // Radio en kilómetros
        ];
        
        // Limpiar parámetros vacíos
        $apiParams = array_filter($defaultParams, function($value) {
            return $value !== null && $value !== '';
        });
        
        try {
            // Usar el método getTaxa del servicio iNaturalist para buscar especies por ubicación
            $apiResult = $this->iNaturalistService->searchTaxon('', array_merge($apiParams, [
                'only_id' => false,
                'rank' => 'species', // Solo especies, no géneros o familias
                'has[]' => 'photos', // Solo especies con fotos
                'geo' => 'true', // Solo especies con datos de ubicación
                'verifiable' => 'true', // Solo observaciones verificables
            ]));
            
            if (!$apiResult['success']) {
                Log::error('Error al obtener especies cercanas', [
                    'params' => $apiParams,
                    'error' => $apiResult['error'] ?? 'Error desconocido',
                    'location' => $location->toArray()
                ]);
                
                return [
                    'success' => false,
                    'error' => $apiResult['error'] ?? ['message' => 'Error al obtener las especies cercanas'],
                    'source' => 'api',
                ];
            }
            
            // Procesar los resultados
            $taxa = $apiResult['data'] ?? [];
            $normalizedSpecies = [];
            
            foreach ($taxa as $taxon) {
                if (is_array($taxon)) {
                    $normalizedSpecies[] = $this->normalizeTaxonData($taxon);
                }
            }
            
            return [
                'success' => true,
                'data' => $normalizedSpecies,
                'pagination' => [
                    'total' => $apiResult['total'] ?? count($normalizedSpecies),
                    'per_page' => $apiResult['per_page'] ?? count($normalizedSpecies),
                    'current_page' => $apiResult['page'] ?? 1,
                    'last_page' => $apiResult['total_pages'] ?? 1,
                ],
                'cached' => $apiResult['cached'] ?? false,
                'source' => 'api',
                'location' => [
                    'name' => $location->name,
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'radius_km' => $location->radius_km
                ]
            ];
            
        } catch (\Exception $e) {
            Log::error('Excepción al obtener especies cercanas: ' . $e->getMessage(), [
                'params' => $apiParams,
                'location' => $location ? $location->toArray() : null,
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => ['message' => 'Error al procesar la respuesta de la API: ' . $e->getMessage()],
                'source' => 'api',
            ];
        }
    }
    
    /**
     * Formatea un modelo Taxa para la respuesta de la API
     *
     * @param \App\Models\Taxa $taxon
     * @return array
     */
    protected function formatTaxonForResponse(\App\Models\Taxa $taxon): array
    {
        return [
            'id' => $taxon->id,
            'name' => $taxon->scientific_name ?? $taxon->name,
            'scientific_name' => $taxon->scientific_name ?? $taxon->name,
            'common_name' => $taxon->common_name,
            'rank' => $taxon->rank,
            'rank_level' => $taxon->rank_level,
            'observations_count' => $taxon->observations_count ?? 0,
            'default_photo' => $taxon->default_photo ? [
                'url' => $taxon->default_photo,
                'attribution' => $taxon->photo_attribution ?? '',
            ] : null,
            'conservation_status' => $taxon->conservation_status ? [
                'status' => $taxon->conservation_status,
                'status_name' => $this->getStatusName($taxon->conservation_status),
            ] : null,
            'wikipedia_url' => $taxon->wikipedia_url,
            'created_at' => $taxon->created_at ? $taxon->created_at->toIso8601String() : null,
            'updated_at' => $taxon->updated_at ? $taxon->updated_at->toIso8601String() : null,
        ];
    }
    
    /**
     * Obtiene el nombre legible del estado de conservación
     *
     * @param string $status
     * @return string
     */
    protected function getStatusName(string $status): string
    {
        $statuses = [
            'LC' => 'Preocupación Menor',
            'NT' => 'Casi Amenazado',
            'VU' => 'Vulnerable',
            'EN' => 'En Peligro',
            'CR' => 'En Peligro Crítico',
            'EW' => 'Extinto en Estado Silvestre',
            'EX' => 'Extinto',
            'DD' => 'Datos Insuficientes',
            'NE' => 'No Evaluado'
        ];
        
        return $statuses[$status] ?? $status;
    }
    
    /**
     * Normaliza los datos de un taxón para una respuesta consistente
     *
     * @param array $taxonData
     * @return array
     */
    protected function normalizeTaxonData(array $taxonData): array
    {
        return [
            'id' => $taxonData['id'] ?? null,
            'scientific_name' => $taxonData['name'] ?? null,
            'common_name' => $taxonData['preferred_common_name'] ?? 
                           ($taxonData['english_common_name'] ?? 
                           ($taxonData['spanish_common_name'] ?? null)),
            'rank' => $taxonData['rank'] ?? null,
            'rank_level' => $taxonData['rank_level'] ?? null,
            'extinct' => $taxonData['extinct'] ?? false,
            'wikipedia_url' => $taxonData['wikipedia_url'] ?? null,
            'wikipedia_summary' => $taxonData['wikipedia_summary'] ?? null,
            'default_photo' => [
                'url' => $taxonData['default_photo']['url'] ?? null,
                'attribution' => $taxonData['default_photo']['attribution'] ?? null,
                'license_code' => $taxonData['default_photo']['license_code'] ?? null,
            ] ?? null,
            'ancestry' => $taxonData['ancestry'] ?? null,
            'conservation_status' => $taxonData['conservation_status'] ?? null,
            'taxon_schemes_count' => $taxonData['taxon_schemes_count'] ?? 0,
            'observations_count' => $taxonData['observations_count'] ?? 0,
            'taxon_changes_count' => $taxonData['taxon_changes_count'] ?? 0,
            'is_active' => $taxonData['is_active'] ?? true,
            'created_at' => $taxonData['created_at'] ?? now()->toDateTimeString(),
            'updated_at' => $taxonData['updated_at'] ?? now()->toDateTimeString(),
        ];
    }
    
    /**
     * Crea o actualiza un taxón a partir de los datos de la API
     *
     * @param array $taxonData
     * @return Taxa|null
     */
    protected function createOrUpdateTaxonFromApiData(array $taxonData): ?Taxa
{
    $scientificName = $taxonData['scientific_name'] ?? $taxonData['name'] ?? null;
    Log::info('Procesando taxón API', ['scientific_name' => $scientificName, 'keys' => array_keys($taxonData)]);  // ← TEMP: Para debug
    if (!$scientificName) {
        Log::warning('Saltando taxón sin scientific_name', ['data_keys' => array_keys($taxonData)]);
        return null;
    }

    try {
        DB::beginTransaction();

        $taxon = Taxa::where('scientific_name', $scientificName)->first();
        if (!$taxon) {
            $taxon = new Taxa(['scientific_name' => $scientificName]);
        }

        $taxon->common_name = $taxonData['preferred_common_name'] ?? $this->getCommonNameFromApi($taxonData);

        // ← Fix: Maneja ancestry como IDs (no names); por ahora, usa nulls o fetch si quieres
        $ancestry = $this->extractAncestryFromApi($taxonData);
        $taxon->kingdom = $ancestry['kingdom'] ?? null;
        $taxon->phylum = $ancestry['phylum'] ?? null;
        $taxon->class = $ancestry['class'] ?? null;
        $taxon->order_name = $ancestry['order'] ?? null;
        $taxon->family = $ancestry['family'] ?? null;
        $taxon->genus = $ancestry['genus'] ?? null;
        $taxon->species = $ancestry['species'] ?? null;

        $taxon->conservation_status = $this->mapConservationStatus($taxonData['conservation_statuses'] ?? $taxonData['conservation_status'] ?? null);  // ← Fix: Toma 'statuses' si existe

        $taxon->observation_count = ($taxon->observation_count ?? 0) + ($taxonData['observations_count'] ?? 0);
        $taxon->last_observed_at = now();
        $taxon->is_native = $taxonData['is_native'] ?? true;
        $taxon->is_endemic = $taxonData['is_endemic'] ?? false;

        $taxon->save();  // ← Si falla aquí, log en catch

        $this->updateOrCreateApiReference($taxon, $taxonData);

        DB::commit();

        Log::info('Taxón guardado exitosamente', ['id' => $taxon->id]);

        return $taxon->load('apiReferences');

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error guardando taxón: ' . $e->getMessage(), ['scientific_name' => $scientificName, 'trace' => $e->getTraceAsString()]);  // ← TEMP: Más detalle
        return null;
    }
}

    /**
 * Extrae nombre común de datos API
 */
protected function getCommonNameFromApi(array $taxonData): ?string
{
    return $taxonData['preferred_common_name'] ?? 
           ($taxonData['common_names'][0]['name'] ?? null) ?? 
           null;
}

/**
 * Extrae ancestry para jerarquía (de iNaturalist)
 */
protected function extractAncestryFromApi(array $taxonData): array
{
    $ancestry = [];
    if (isset($taxonData['ancestry']) && is_string($taxonData['ancestry'])) {
        // iNaturalist usa comma IDs, no | names
        $parts = explode(',', $taxonData['ancestry']);
        if (count($parts) >= 7) {  // Mínimo para kingdom to species
            // Por ahora, nulls; para names, fetch /taxa/{id} por cada (lento, haz lazy si necesitas)
            $ancestry = [
                'kingdom' => null,  // Fetch later if needed
                'phylum' => null,
                'class' => null,
                'order' => null,
                'family' => null,
                'genus' => null,
                'species' => null,
            ];
        }
    } elseif (isset($taxonData['ancestors']) && is_array($taxonData['ancestors'])) {
        // Si normalizas a array con names
        foreach ($taxonData['ancestors'] as $ancestor) {
            $ancestry[strtolower($ancestor['rank'] ?? '')] = $ancestor['name'] ?? null;
        }
    }
    // Fallback para genus/species de name
    if (isset($taxonData['name']) && strpos($taxonData['name'], ' ') !== false) {
        [$genus, $species] = explode(' ', $taxonData['name'], 2);
        $ancestry['genus'] = $genus;
        $ancestry['species'] = $species;
    }
    return $ancestry;
}

/**
 * Mapea status de conservación a tu ENUM (maneja array de API)
 */
protected function mapConservationStatus($apiStatus): ?string
{
    // FIX: Si es array (de iNaturalist), toma el status del primero (e.g., IUCN global)
    if (is_array($apiStatus) && !empty($apiStatus)) {
        $apiStatus = $apiStatus[0]['status'] ?? null;  // O $apiStatus[0]['iucn_status'] si es el campo
    }
    
    // Si aún no es string, null
    if (!is_string($apiStatus)) {
        return 'NE';
    }
    
    $map = [
        'least_concern' => 'LC',
        'near_threatened' => 'NT',
        'vulnerable' => 'VU',
        'endangered' => 'EN',
        'critically_endangered' => 'CR',
        'extinct_in_the_wild' => 'EW',
        'extinct' => 'EX',
        // Agrega más si necesitas (e.g., 'data_deficient' => 'DD')
    ];
    return $map[$apiStatus] ?? 'NE';  // No Evaluado
}

/**
 * Actualiza/crea referencia API (JSON completo)
 */

    
    /**
     * Actualiza o crea una referencia a la API para un taxón
     *
     * @param Taxa $taxon
     * @param array $taxonData
     * @return void
     */
    protected function updateOrCreateApiReference(Taxa $taxon, array $taxonData): void
{
    // Primero, guarda la ref principal
    TaxonApiReference::updateOrCreate(
        ['taxon_id' => $taxon->id, 'api_source' => 'inaturalist'],
        [
            'external_id' => $taxonData['id'] ?? null,
            'api_url' => $taxonData['url'] ?? null,
            'confidence_score' => 1.0,
            'is_primary' => true,
            'last_verified_at' => now(),
            'data' => $taxonData,  // Completo
        ]
    );

    // FIX: Si cacheas fotos (de 'default_photo' o 'taxon_photos'), pasa response_data
    if (isset($taxonData['default_photo']) || isset($taxonData['taxon_photos'])) {
        $photoData = $taxonData['default_photo'] ?? $taxonData['taxon_photos'][0] ?? null;
        if ($photoData) {
            $cacheKey = 'taxon_photo_' . $taxon->id;  // O usa $taxonData['id']
            UnifiedApiCache::updateOrCreate(
                ['cache_key' => $cacheKey, 'api_source' => 'inaturalist'],
                [
                    'response_data' => json_encode($photoData),  // ← FIX: Siempre pasa esto (JSON del photo)
                    'expires_at' => now()->addDays(7),  // TTL ejemplo
                ]
            );
        }
    }
}
    
    /**
     * Procesa y almacena las observaciones de un taxón
     *
     * @param array $observations
     * @param int $taxonId
     * @return array
     */
    public function processAndStoreObservations(array $observations, int $taxonId): array
    {
        $storedCount = 0;
        $errors = [];
        
        foreach ($observations as $observation) {
            try {
                $this->createOrUpdateObservation($observation, $taxonId);
                $storedCount++;
            } catch (\Exception $e) {
                $errors[] = [
                    'observation_id' => $observation['id'] ?? 'unknown',
                    'error' => $e->getMessage(),
                ];
                Log::error('Error al procesar observación: ' . $e->getMessage(), [
                    'observation' => $observation,
                    'exception' => $e
                ]);
            }
        }
        
        return [
            'total_processed' => count($observations),
            'stored_count' => $storedCount,
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }
    
    /**
     * Crea o actualiza una observación en la base de datos
     *
     * @param array $observationData
     * @param int $taxonId
     * @return \App\Models\Observation
     * @throws \Exception
     */
    protected function createOrUpdateObservation(array $observationData, int $taxonId)
    {
        // Aquí implementarías la lógica para guardar la observación
        // Este es un ejemplo básico que necesitarías adaptar a tu modelo de datos
        
        $observation = \App\Models\Observation::updateOrCreate(
            ['id' => $observationData['id']],
            [
                'taxon_id' => $taxonId,
                'observed_on' => $observationData['observed_on'] ?? null,
                'location' => $observationData['location'] ?? null,
                'quality_grade' => $observationData['quality_grade'] ?? null,
                'data' => json_encode($observationData),
            ]
        );
        
        // Procesar y guardar fotos asociadas a la observación
        if (!empty($observationData['photos'])) {
            $this->processObservationPhotos($observation, $observationData['photos']);
        }
        
        return $observation;
    }
    
    /**
     * Procesa y guarda las fotos de una observación
     *
     * @param \App\Models\Observation $observation
     * @param array $photos
     * @return void
     */
    protected function processObservationPhotos(\App\Models\Observation $observation, array $photos): void
    {
        foreach ($photos as $photoData) {
            $photo = $observation->photos()->updateOrCreate(
                ['id' => $photoData['id']],
                [
                    'url' => $photoData['url'] ?? null,
                    'license_code' => $photoData['license_code'] ?? null,
                    'attribution' => $photoData['attribution'] ?? null,
                ]
            );
            
            // Aquí podrías agregar lógica para descargar y almacenar las imágenes localmente
            // si es necesario para tu aplicación
        }
    }
}