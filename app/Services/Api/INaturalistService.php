<?php

namespace App\Services\Api;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class INaturalistService extends BaseApiService
{
    /**
     * @var string
     */
    protected $apiName = 'inaturalist';
    
    /**
     * Tiempo de vida de la caché para búsquedas (en minutos)
     * 
     * @var int
     */
    protected $searchCacheTtl = 1440; // 24 horas
    
    /**
     * Tiempo de vida de la caché para detalles de taxones (en días)
     * 
     * @var int
     */
    protected $taxonCacheTtl = 10080; // 7 días
    
    /**
     * Inicialización del servicio
     */
    protected function initialize()
    {
        parent::initialize();
        
        // Configuración específica de iNaturalist
        $this->config['base_url'] = $this->config['base_url'] ?? 'https://api.inaturalist.org/v1';
        $this->config['per_page'] = $this->config['per_page'] ?? 30;
    }
    
    /**
     * Obtiene los parámetros por defecto para las solicitudes
     *
     * @return array
     */
    protected function getDefaultParams(): array
    {
        return [
            'locale' => 'es', // Establece el idioma a español
        ];
    }
    
    /**
     * Obtiene información de un taxón por su ID
     * CORREGIDO: Ahora incluye preferred_establishment_means para Colombia
     *
     * @param string $id
     * @param array $params Parámetros adicionales
     * @return array
     */
    public function getTaxonById(string $id, array $params = []): array
    {
        // ✅ CRÍTICO: Usar preferred_place_id en lugar de place_id
        // Este parámetro le dice a iNaturalist que incluya establishment status para ese lugar
        $defaultParams = array_merge($this->getDefaultParams(), [
            'preferred_place_id' => config('services.inaturalist.preferred_place_id', 7196), // Colombia - ESTO es lo que faltaba
            'locale' => 'es',
        ], $params);

        // Log para debug
        Log::info('🔍 Consultando taxón con params', [
            'id' => $id,
            'params' => $defaultParams
        ]);

        $response = $this->makeRequest('get', "/taxa/{$id}", $defaultParams);
        
        if (!$response['success']) {
            return $response;
        }
        
        // Procesar la respuesta
        $taxonData = $response['data']['results'][0] ?? null;
        
        if (!is_array($taxonData)) {
            return [
                'success' => false,
                'error' => ['message' => 'Formato de respuesta inesperado de la API'],
                'api' => $this->apiName,
            ];
        }
        
        if (!$taxonData) {
            return [
                'success' => false,
                'error' => ['message' => 'Taxón no encontrado'],
                'api' => $this->apiName,
            ];
        }

        // ✅ Log para verificar que ahora sí llega el establishment status
        Log::info('✅ Datos de taxón recibidos', [
            'id' => $id,
            'has_preferred_establishment_means' => isset($taxonData['preferred_establishment_means']),
            'preferred_establishment_means' => $taxonData['preferred_establishment_means'] ?? 'NULL',
            'has_establishment_means' => isset($taxonData['establishment_means']),
            'establishment_means_count' => count($taxonData['establishment_means'] ?? [])
        ]);
        
        $normalizedTaxon = $this->normalizeTaxonData($taxonData);
        
        return [
            'success' => true,
            'data' => $normalizedTaxon,
            'cached' => $response['cached'] ?? false,
            'api' => $this->apiName,
        ];
    }
    /**
     * Obtiene múltiples taxones por sus IDs (Bulk)
     * Utiliza preferred_place_id para obtener establishment status correcto
     */
    public function getTaxaByIds(array $ids, array $params = []): array
    {
        if (empty($ids)) return ['success' => true, 'data' => []];

        $defaultParams = array_merge($this->getDefaultParams(), [
            'preferred_place_id' => config('services.inaturalist.preferred_place_id', 7196),
            'per_page' => count($ids), // Ask for exactly this many
            'page' => 1,
            'id' => implode(',', $ids),
            'locale' => 'es',
        ], $params);

        $response = $this->makeRequest('get', '/taxa', $defaultParams);
        
        if (!$response['success']) return $response;
        
        $results = $response['data']['results'] ?? [];
        $normalized = [];
        foreach ($results as $result) {
            // Use normalizeTaxonDataLight because we are merging into a list view
            // But we need to make sure Light normalizer includes establishment fields (which I fixed earlier)
            try {
                // Actually, let's use the FULL normalizer if we want richness? 
                // No, the user wants badges, but we still need it to match the list format.
                // The Light normalizer now has the establishment logic I added. 
                // But wait, the SOURCE data ($result) will now HAVE preferred_establishment_means.
                // So Light normalizer will pick it up correctly.
                $normalized[] = $this->normalizeTaxonDataLight($result);
            } catch (\Exception $e) { }
        }
        
        return [
            'success' => true,
            'data' => $normalized,
        ];
    }

    /**
     * Busca taxones por nombre científico o común
     *
     * @param string $query
     * @param array $filters
     * @return array
     */
   public function searchTaxon(string $query, array $filters = []): array
    {
        $defaultParams = $this->getDefaultParams();
        
        // ✅ CRÍTICO: Usar preferred_place_id para obtener establishment status
        $colombiaParams = [
            'preferred_place_id' => config('services.inaturalist.preferred_place_id', 7196),  // Colombia - esto es lo importante
            'locale' => 'es',
        ];
        
        $paginationParams = [
            'q' => $query,
            'per_page' => $filters['per_page'] ?? 15,
            'page' => $filters['page'] ?? 1,
        ];
        
        $filteredFilters = array_diff_key($filters, array_flip(['per_page', 'page']));
        
        $params = array_merge(
            $defaultParams,
            $colombiaParams,
            $paginationParams,
            $filteredFilters
        );
        
        $params = array_filter($params, function($value) {
            return $value !== null && $value !== '';
        });
        
        Log::info('🔍 Params para iNaturalist search', [
            'params' => $params,
            'has_preferred_place_id' => isset($params['preferred_place_id'])
        ]);
        
        $response = $this->makeRequest('get', '/taxa', $params, true);

        if (!$response['success']) {
            return $response;
        }
        
        $rawResults = $response['data']['results'] ?? [];
        
        // Log para verificar si ahora trae el establishment status
        if (!empty($rawResults)) {
            Log::info('✅ Primer resultado de búsqueda', [
                'id' => $rawResults[0]['id'] ?? null,
                'name' => $rawResults[0]['name'] ?? null,
                'has_preferred_establishment_means' => isset($rawResults[0]['preferred_establishment_means']),
                'preferred_establishment_means' => $rawResults[0]['preferred_establishment_means'] ?? 'NULL',
                'native' => $rawResults[0]['native'] ?? false,
                'endemic' => $rawResults[0]['endemic'] ?? false,
                'introduced' => $rawResults[0]['introduced'] ?? false
            ]);
        }
        
        $normalizedResults = [];
        foreach ($rawResults as $index => $result) {
            try {
                $normalized = $this->normalizeTaxonData($result);
                $normalizedResults[] = $normalized;
            } catch (\Exception $e) {
                Log::error("💥 Error normalizando resultado {$index}", [
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        return [
            'success' => true,
            'data' => $normalizedResults,
            'pagination' => [
                'page' => $response['data']['page'] ?? 1,
                'per_page' => $response['data']['per_page'] ?? $this->config['per_page'],
                'total' => $response['data']['total_results'] ?? count($normalizedResults),
            ],
            'cached' => $response['cached'] ?? false,
            'api' => $this->apiName,
        ];
    }
    
    /**
     * Obtiene las observaciones de un taxón específico
     *
     * @param string $taxonId
     * @param array $params
     * @return array
     */
   public function getTaxonObservations(string $taxonId, array $params = []): array
{
    // Combinar parámetros por defecto con los proporcionados
    $defaultParams = array_merge(
        $this->getDefaultParams(),
        [
            'taxon_id' => $taxonId,
            'place_id' => $params['place_id'] ?? config('services.inaturalist.place_id', 7196),  // Colombia por defecto
            'per_page' => $params['per_page'] ?? 100,
            'order_by' => $params['order_by'] ?? 'created_at',
            'order' => $params['order'] ?? 'desc',
            'quality_grade' => 'research,needs_id',  // Validadas + pendientes
            'photos' => 'true',
            // REMOVIDO: 'identifications' => 'most_agree' (inválido para /observations)
            'locale' => 'es',
        ]
    );
    
    // Limpiar nulos/vacíos
    $defaultParams = array_filter($defaultParams, fn($value) => $value !== null && $value !== '');

    $logUrl = $this->config['base_url'] . '/observations?' . http_build_query($defaultParams);
    Log::info('🔍 Params para getTaxonObservations', ['url' => $logUrl, 'params' => $defaultParams]);

    $response = $this->makeRequest('get', '/observations', $defaultParams, true);

    if (!$response['success']) {
        Log::error('Error en getTaxonObservations', ['error' => $response['error'] ?? 'unknown']);
        return $response;
    }

    $rawResults = $response['data']['results'] ?? [];
    Log::info('🔍 DEBUG getTaxonObservations - Estructura', [
        'total_results' => $response['data']['total_results'] ?? 0,
        'results_count' => count($rawResults),
        'first_result_keys' => !empty($rawResults) ? array_keys($rawResults[0]) : [],
    ]);

    // Normalizar observaciones
    $normalizedResults = [];
    foreach ($rawResults as $index => $obs) {
        try {
            $normalized = $this->normalizeObservationData($obs);
            $normalizedResults[] = $normalized;
        } catch (\Exception $e) {
            Log::error("💥 Error normalizando observación {$index}", ['error' => $e->getMessage()]);
            continue;
        }
    }

    return [
        'success' => true,
        'data' => $normalizedResults,
        'pagination' => [
            'page' => $response['data']['page'] ?? 1,
            'per_page' => $response['data']['per_page'] ?? 100,
            'total' => $response['data']['total_results'] ?? count($normalizedResults),
        ],
        'cached' => $response['cached'] ?? false,
        'api' => $this->apiName,
    ];
}
    
    /**
     * Obtiene información de una ubicación específica
     *
     * @param string $locationId
     * @return array
     */
    public function getLocationInfo(string $locationId): array
    {
        $endpoint = "/places/{$locationId}";
        $response = $this->makeRequest('get', $endpoint, [], true);
        
        if (!$response['success']) {
            return $response;
        }
        
        $locationData = $response['data']['results'][0] ?? null;
        
        if (!$locationData) {
            return [
                'success' => false,
                'error' => ['message' => 'Ubicación no encontrada'],
                'api' => $this->apiName,
            ];
        }
        
        $normalizedLocation = $this->normalizeLocationData($locationData);
        
        return [
            'success' => true,
            'data' => $normalizedLocation,
            'cached' => $response['cached'] ?? false,
            'api' => $this->apiName,
        ];
    }
    
    /**
     * Obtiene observaciones de iNaturalist con filtros personalizables
     *
     * @param array $params Parámetros de búsqueda
     * @return array
     */
    public function getObservations(array $params = []): array
    {
        try {
            // Combinar con parámetros por defecto
            $defaultParams = array_merge(
                [
                    'per_page' => 30,
                    'page' => 1,
                    'order_by' => 'created_at',
                    'order' => 'desc',
                    'quality_grade' => 'research,needs_id',
                    'photos' => 'true',
                    'identifications' => 'most_agree',
                    'taxon_geoprivacy' => 'open',
                ],
                $params
            );
            
            // Limpiar parámetros vacíos
            $params = array_filter($defaultParams, function($value) {
                return $value !== null && $value !== '';
            });
            
            // Asegurarse de que los parámetros estén limpios
            $params = $this->cleanParams($params);
            
            // Realizar la petición a la API de iNaturalist
            $response = $this->makeRequest('get', '/observations', $params, true);
            
            if (!$response['success']) {
                Log::error('Error en la API de iNaturalist', [
                    'params' => $params,
                    'error' => $response['error'] ?? 'Error desconocido'
                ]);
                
                return [
                    'success' => false,
                    'error' => $response['error'] ?? ['message' => 'Error al obtener observaciones de iNaturalist'],
                    'api' => $this->apiName,
                ];
            }
            
            // Procesar y normalizar las observaciones
            $observations = $response['data']['results'] ?? [];
            $normalizedObservations = [];
            
            foreach ($observations as $observation) {
                if (isset($observation['taxon'])) {
                    $normalizedObservations[] = $this->normalizeObservationData($observation);
                }
            }
            
            return [
                'success' => true,
                'data' => $normalizedObservations,
                'total' => $response['data']['total_results'] ?? count($normalizedObservations),
                'per_page' => $response['data']['per_page'] ?? $params['per_page'],
                'page' => $response['data']['page'] ?? $params['page'],
                'total_pages' => $response['data']['total_pages'] ?? 1,
                'cached' => $response['cached'] ?? false,
                'api' => $this->apiName,
            ];
            
        } catch (\Exception $e) {
            Log::error('Excepción en getObservations: ' . $e->getMessage(), [
                'params' => $params ?? [],
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'error' => ['message' => 'Error al procesar la respuesta de iNaturalist: ' . $e->getMessage()],
                'api' => $this->apiName,
            ];
        }
    }

    /**
     * Obtiene contadores de especies (lista de especies) para una ubicación y filtros dados.
     * Endpoint: /observations/species_counts
     * 
     * @param array $params
     * @return array
     */
    public function getSpeciesCounts(array $params = []): array
    {
        $defaultParams = array_merge($this->getDefaultParams(), [
            'per_page' => 24,
            'page' => 1,
            'hrank' => 'species', // Highest rank species
            'lrank' => 'species', // Lowest rank species (only species)
            'verifiable' => 'true',
            'quality_grade' => 'research', // Filter high quality data
            'photos' => 'true',
            'place_id' => config('services.inaturalist.place_id', 7196) // Default Colombia
        ], $params);

        // Map 'q' to 'q' (generic search) or handle if necessary. 
        // Note: species_counts supports 'taxon_id' but 'q' might search within species names if supported by iNat.
        // iNat API for species_counts DOES NOT strictly support 'q' for species name search in the same way as /taxa.
        // It relies more on 'taxon_id' filter. 
        // However, we will pass whatever params we have.

        // Clean params
        $params = array_filter($defaultParams, function($value) {
            return $value !== null && $value !== '';
        });

        Log::info('🔍 Params para getSpeciesCounts', [
            'params' => $params
        ]);

        // Cache short-term to reduce latency under pagination/navigation
        $cacheKey = 'inat_species_counts_' . md5(json_encode($params));
        $response = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($params) {
            return $this->makeRequest('get', '/observations/species_counts', $params, true);
        });

        if (!$response['success']) {
            return $response;
        }

        $rawResults = $response['data']['results'] ?? [];
        $normalizedResults = [];

        foreach ($rawResults as $result) {
            // Result structure is { count: X, taxon: { ... } }
            if (isset($result['taxon'])) {
                try {
                    // Use lightweight normalizer to avoid heavy fields (gallery, ancestors, etc.)
                    $taxon = $this->normalizeTaxonDataLight($result['taxon']);
                    // Inject observation count from this endpoint if useful
                    $taxon['observations_count'] = $result['count'] ?? 0;
                    $normalizedResults[] = $taxon;
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        return [
            'success' => true,
            'data' => $normalizedResults,
            'pagination' => [
                'page' => $response['data']['page'] ?? 1,
                'per_page' => $response['data']['per_page'] ?? $params['per_page'],
                'total' => $response['data']['total_results'] ?? count($normalizedResults),
            ],
            'cached' => $response['cached'] ?? false,
            'api' => $this->apiName,
        ];
    }
    
    /**
     * Lightweight normalizer for list views (species_counts)
     * Keeps only essential fields to render cards quickly
     */
    protected function normalizeTaxonDataLight(array $taxonData): array
    {
        $commonName = $this->getCommonName($taxonData);
        // Conservation minimal extraction
        $conservation = null;
        if (!empty($taxonData['conservation_status'])) {
            $cs = $taxonData['conservation_status'];
            $conservation = [
                'status' => $cs['status'] ?? null,
                'status_name' => $cs['status_name'] ?? ($cs['iucn'] ?? null),
            ];
        }

        return [
            'id' => $taxonData['id'] ?? null,
            'scientific_name' => $taxonData['name'] ?? $taxonData['scientific_name'] ?? null,
            'common_name' => $commonName,
            'rank' => $taxonData['rank'] ?? null,
            'rank_level' => $taxonData['rank_level'] ?? null,
            'default_photo' => $this->extractPhotoData($taxonData['default_photo'] ?? null),
            'native' => (bool)($taxonData['native'] ?? false),
            'endemic' => (bool)($taxonData['endemic'] ?? false),
            'introduced' => (bool)($taxonData['introduced'] ?? false),
            'preferred_establishment_means' => $taxonData['preferred_establishment_means'] ?? null,
            // Calculate establishment_status_colombia for frontend badges
            'establishment_status_colombia' => ($taxonData['endemic'] ?? false) ? 'endemic' : 
                                              (($taxonData['native'] ?? false) ? 'native' : 
                                              (($taxonData['introduced'] ?? false) ? 'introduced' : 
                                              ($taxonData['preferred_establishment_means'] ?? null))),
            'conservation_status' => $conservation,
            'wikipedia_url' => $taxonData['wikipedia_url'] ?? null,
            'iconic_taxon_name' => $taxonData['iconic_taxon_name'] ?? null,
            // Omit gallery, ancestors, listed_taxa_count, etc. for speed
        ];
    }
    /**
     * Obtiene información general sobre la API de iNaturalist
     *
     * @return array
     */
    public function getApiInfo(): array
    {
        return [
            'name' => 'iNaturalist',
            'version' => 'v1',
            'documentation' => 'https://api.inaturalist.org/v1/docs/',
            'rate_limit' => $this->config['rate_limit'] ?? 100,
            'requests_remaining' => $this->config['requests_remaining'] ?? null,
        ];
    }
    
    /**
 * Normaliza los datos de un taxón de la API de iNaturalist
 *
 * @param array $taxonData
 * @return array
 */
    protected function normalizeTaxonData(array $taxonData): array
    {
        $commonName = $this->getCommonName($taxonData);
        
        return [
            'id' => $taxonData['id'] ?? null,
            'scientific_name' => $taxonData['name'] ?? $taxonData['scientific_name'] ?? 'Sin nombre científico',
            'common_name' => $commonName,
            'rank' => $taxonData['rank'] ?? null,
            'rank_level' => $taxonData['rank_level'] ?? null,
            'ancestry' => isset($taxonData['ancestry']) ? explode('/', $taxonData['ancestry']) : [],
            'is_active' => (bool)($taxonData['is_active'] ?? true),
            'extinct' => (bool)($taxonData['extinct'] ?? false),
            'threatened' => (bool)($taxonData['threatened'] ?? false),
            'introduced' => (bool)($taxonData['introduced'] ?? false),
            'native' => (bool)($taxonData['native'] ?? false),
            'endemic' => (bool)($taxonData['endemic'] ?? false),
            
            // ✅ NUEVO: Campos de establishment
            'establishment_means' => $taxonData['establishment_means'] ?? null,
            'preferred_establishment_means' => $taxonData['preferred_establishment_means'] ?? null,
            
            // ✅ NUEVO: Estado de conservación
            'conservation_status' => $this->extractConservationStatus(
                $taxonData['conservation_status'] ?? 
                $taxonData['conservation_statuses'] ?? 
                null
            ),
            // OPTIMIZACIÓN: No guardar array completo de conservation_statuses si no es necesario
            // 'conservation_statuses' => $taxonData['conservation_statuses'] ?? [],
            
            // ✅ NUEVO: Listed taxa (optimizado) - solo guardamos contadores o lo mínimo si es necesario
            // Para ahorrar espacio, no guardamos el array completo a menos que sea crítico
            'listed_taxa_count' => $taxonData['listed_taxa_count'] ?? count($taxonData['listed_taxa'] ?? []),
            
            'wikipedia_url' => $taxonData['wikipedia_url'] ?? null,
            'wikipedia_summary' => $taxonData['wikipedia_summary'] ?? null,
            'default_photo' => $this->extractPhotoData($taxonData['default_photo'] ?? null),
            
            // OPTIMIZACIÓN: Eliminados taxon_schemes, taxon_changes, etc.
            'observations_count' => $taxonData['observations_count'] ?? 0,
            
            'iconic_taxon_id' => $taxonData['iconic_taxon_id'] ?? null,
            'iconic_taxon_name' => $taxonData['iconic_taxon_name'] ?? null,
            'preferred_common_name' => $taxonData['preferred_common_name'] ?? null,
            
            // ✅ NUEVO: Galería optimizada (Top 5 fotos de taxon_photos)
            'gallery' => $this->extractGallery($taxonData['taxon_photos'] ?? []),
            
            'ancestors' => $this->extractAncestors($taxonData['ancestors'] ?? []),
            
            'created_at' => $taxonData['created_at'] ?? null,
            'updated_at' => $taxonData['updated_at'] ?? null,
            'source' => 'inaturalist',
        ];
    }
    
    /**
     * Normaliza los datos de una observación
     *
     * @param array $observationData
     * @return array
     */
    protected function normalizeObservationData(array $observationData): array
    {
        $taxon = $observationData['taxon'] ?? null;
        $placeGuess = $observationData['place_guess'] ?? null;
        $location = $observationData['location'] ? explode(',', $observationData['location']) : null;
        
        return [
            'id' => $observationData['id'],
            'observed_on' => $observationData['observed_on'],
            'time_observed_at' => $observationData['time_observed_at'] ?? null,
            'time_zone' => $observationData['time_zone'] ?? null,
            'quality_grade' => $observationData['quality_grade'] ?? null,
            'license' => $observationData['license'] ?? null,
            'url' => $observationData['uri'] ?? null,
            'captive' => (bool)($observationData['captive'] ?? false),
            'location' => $location ? [
                'latitude' => $location[0] ?? null,
                'longitude' => $location[1] ?? null,
            ] : null,
            'place_guess' => $placeGuess,
            'taxon' => $taxon ? [
                'id' => $taxon['id'],
                'name' => $taxon['name'],
                'rank' => $taxon['rank'],
                'rank_level' => $taxon['rank_level'] ?? null,
                'common_name' => $this->getCommonName($taxon),
                'default_photo' => $this->extractPhotoData($taxon['default_photo'] ?? null),
            ] : null,
            'photos' => array_map(function($photo) {
                return $this->extractPhotoData($photo);
            }, $observationData['photos'] ?? []),
            'created_at' => $observationData['created_at'] ?? null,
            'updated_at' => $observationData['updated_at'] ?? null,
        ];
    }
    
    /**
     * Normaliza los datos de una ubicación
     *
     * @param array $locationData
     * @return array
     */
    protected function normalizeLocationData(array $locationData): array
    {
        return [
            'id' => $locationData['id'],
            'name' => $locationData['name'] ?? null,
            'display_name' => $locationData['display_name'] ?? null,
            'admin_level' => $locationData['admin_level'] ?? null,
            'place_type' => $locationData['place_type'] ?? null,
            'location' => $locationData['location'] ? [
                'latitude' => $locationData['location'][1] ?? null,
                'longitude' => $locationData['location'][0] ?? null,
            ] : null,
            'bbox_area' => $locationData['bbox_area'] ?? null,
            'place_guess' => $locationData['place_guess'] ?? null,
            'place_guess_name' => $locationData['place_guess'] ? $this->extractPlaceName($locationData['place_guess']) : null,
            'ancestor_place_ids' => $locationData['ancestor_place_ids'] ?? [],
            'geometry_geojson' => $locationData['geometry_geojson'] ?? null,
            'created_at' => $locationData['created_at'] ?? null,
            'updated_at' => $locationData['updated_at'] ?? null,
        ];
    }
    
    /**
     * Extrae el nombre común de un taxón, si está disponible
     *
     * @param array $taxonData
     * @return string|null
     */
    protected function getCommonName(array $taxonData): ?string
    {
        if (!empty($taxonData['preferred_common_name'])) {
            return $taxonData['preferred_common_name'];
        }
        
        if (!empty($taxonData['common_name'])) {
            return $taxonData['common_name']['name'] ?? $taxonData['common_name'];
        }
        
        if (!empty($taxonData['common_names']) && is_array($taxonData['common_names'])) {
            return $taxonData['common_names'][0]['name'] ?? null;
        }
        
        return null;
    }
    
    /**
     * Extrae información de una foto
     *
     * @param array|null $photoData
     * @return array|null
     */
    protected function extractPhotoData(?array $photoData): ?array
    {
        if (!$photoData) {
            return null;
        }
        
        return [
            'id' => $photoData['id'] ?? null,
            'url' => $photoData['url'] ?? null,
            'original_url' => $photoData['original_url'] ?? null,
            'license_code' => $photoData['license_code'] ?? null,
            'attribution' => $photoData['attribution'] ?? null,
            'square_url' => $photoData['square_url'] ?? null,
            'medium_url' => $photoData['medium_url'] ?? null,
            'small_url' => $photoData['small_url'] ?? null,
            'large_url' => $photoData['large_url'] ?? null,
            'original_dimensions' => [
                'width' => $photoData['original_dimensions']['width'] ?? null,
                'height' => $photoData['original_dimensions']['height'] ?? null,
            ],
        ];
    }
    
    /**
     * Extrae el estado de conservación de un taxón
     *
     * @param array|null $status
     * @return array|null
     */
    protected function extractConservationStatus($status): ?array
{
    if (!$status) {
        return null;
    }
    
    // Si es un array de conservation_statuses, tomar el primero (IUCN global)
    if (is_array($status) && isset($status[0])) {
        $firstStatus = $status[0];
        return [
            'status' => $firstStatus['status'] ?? null,
            'status_name' => $this->getConservationStatusName($firstStatus['status'] ?? null),
            'iucn' => $firstStatus['iucn'] ?? null,
            'authority' => $firstStatus['authority'] ?? null,
            'url' => $firstStatus['url'] ?? null,
            'geoprivacy' => $firstStatus['geoprivacy'] ?? null,
        ];
    }
    
    // Si es un objeto simple
    if (is_array($status)) {
        return [
            'status' => $status['status'] ?? null,
            'status_name' => $status['status_name'] ?? null,
            'iucn' => $status['iucn'] ?? null,
            'authority' => $status['authority'] ?? null,
            'geoprivacy' => $status['geoprivacy'] ?? null,
        ];
    }
    
    return null;
}

/**
 * Obtiene el nombre legible del status de conservación IUCN
 */
protected function getConservationStatusName(?string $status): ?string
{
    if (!$status) {
        return null;
    }
    
    $map = [
        'LC' => 'Least Concern (Preocupación Menor)',
        'NT' => 'Near Threatened (Casi Amenazado)',
        'VU' => 'Vulnerable',
        'EN' => 'Endangered (En Peligro)',
        'CR' => 'Critically Endangered (En Peligro Crítico)',
        'EW' => 'Extinct in the Wild (Extinto en Estado Silvestre)',
        'EX' => 'Extinct (Extinto)',
        'DD' => 'Data Deficient (Datos Insuficientes)',
        'NE' => 'Not Evaluated (No Evaluado)',
    ];
    
    return $map[$status] ?? $status;
}
    
    /**
     * Extrae información de los ancestros de un taxón
     *
     * @param array $ancestors
     * @return array
     */
    protected function extractAncestors(array $ancestors): array
    {
        return array_map(function($ancestor) {
            return [
                'id' => $ancestor['id'] ?? null,
                'name' => $ancestor['name'] ?? null,
                'rank' => $ancestor['rank'] ?? null,
                'rank_level' => $ancestor['rank_level'] ?? null,
            ];
        }, $ancestors);
    }
    
    /**
     * Extrae información de las fotos asociadas a un taxón
     *
     * @param array $taxonPhotos
     * @return array
     */
    protected function extractTaxonPhotos(array $taxonPhotos): array
    {
        return array_map(function($taxonPhoto) {
            return [
                'id' => $taxonPhoto['id'] ?? null,
                'photo' => $this->extractPhotoData($taxonPhoto['photo'] ?? null),
                'taxon_id' => $taxonPhoto['taxon_id'] ?? null,
                'taxon_name' => $taxonPhoto['taxon_name'] ?? null,
            ];
        }, $taxonPhotos);
    }
    
    /**
     * Extrae y procesa la galería de fotos (max 5 mejores)
     *
     * @param array $taxonPhotos
     * @return array
     */
    protected function extractGallery(array $taxonPhotos): array
    {
        if (empty($taxonPhotos)) {
            return [];
        }

        // 1. Filtrar y Normalizar
        $gallery = [];
        foreach ($taxonPhotos as $tp) {
            $photo = $tp['photo'] ?? null;
            if (!$photo) continue;

            $gallery[] = [
                'id' => $photo['id'] ?? null,
                'url' => $photo['original_url'] ?? $photo['large_url'] ?? $photo['medium_url'] ?? $photo['url'],
                'medium_url' => $photo['medium_url'] ?? $photo['url'],
                'small_url' => $photo['small_url'] ?? $photo['square_url'] ?? $photo['url'],
                'attribution' => $photo['attribution'] ?? null,
                'license_code' => $photo['license_code'] ?? null,
                'original_dimensions' => $photo['original_dimensions'] ?? null,
            ];
        }

        // 2. Limitar a 5 fotos
        return array_slice($gallery, 0, 5);
    }

    /**
     * Extrae el nombre de un lugar a partir de una cadena de ubicación
     *
     * @param string $placeString
     * @return string
     */
    protected function extractPlaceName(string $placeString): string
    {
        // Eliminar códigos postales o números al principio
        $placeString = preg_replace('/^\d+[,\s]*/', '', $placeString);
        
        // Dividir por comas y tomar el primer elemento (generalmente el nombre del lugar)
        $parts = explode(',', $placeString);
        return trim($parts[0]);
    }
    


}
