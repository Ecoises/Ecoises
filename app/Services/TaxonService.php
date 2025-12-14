<?php

namespace App\Services;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Models\UnifiedApiCache;
use App\Models\Observation;
use App\Services\Api\INaturalistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

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
                // OPTIMIZACIÓN: No pedir identificaciones
                'identifications' => 'false', 
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
            
            // Enriquecer con datos unidos
            // OPTIMIZACIÓN: En la lista solo mandamos default_photo, NO la galería completa
            $formattedTaxa = collect($savedTaxa)->map(function($taxon) {
                $data = $taxon->enriched_data;
                // Eliminamos datos pesados para la lista
                unset($data['gallery']);
                unset($data['ancestors']);
                unset($data['conservation_statuses']);
                return $data;
            })->toArray();
            
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
            // Enriquecer locales (asumiendo que son Taxa models o paginador)
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
            
            // Enriquecer con datos unidos
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
        $apiResult = $this->iNaturalistService->getTaxonById((string)$id);
        
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
            // Usar el método searchTaxon del servicio iNaturalist para buscar especies por ubicación
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
                    $normalized = $this->normalizeTaxonData($taxon);
                    // OPTIMIZACIÓN: Eliminamos datos pesados para la lista de cercanos
                    unset($normalized['gallery']);
                    unset($normalized['ancestors']);
                    unset($normalized['conservation_statuses']);
                    $normalizedSpecies[] = $normalized;
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
     * Obtiene especies relacionadas (mismo género/familia)
     *
     * @param int $taxonId
     * @return array
     */
    public function getRelatedSpecies(int $taxonId): array
    {
        // 1. Obtener el taxón base para saber su género
        $baseTaxon = $this->getTaxonById($taxonId);
        
        if (!$baseTaxon['success']) {
            return ['success' => false, 'error' => 'Taxón base no encontrado'];
        }

        $taxonData = $baseTaxon['data']->enriched_data ?? $baseTaxon['data'];
        
        // 2. Extraer ID del ancestro (Género o Familia)
        // Intentamos buscar el género en el ancestry
        $ancestors = $taxonData['ancestors'] ?? [];
        $genusId = null;
        
        foreach ($ancestors as $ancestor) {
            if (isset($ancestor['rank']) && $ancestor['rank'] === 'genus') {
                $genusId = $ancestor['id'];
                break;
            }
        }
        
        // Si no encontramos género, usar familia o fallback
        if (!$genusId && !empty($ancestors)) {
            // Usar el ancestro inmediato anterior (padre)
            $parent = end($ancestors);
            $genusId = $parent['id'] ?? null;
        }

        if (!$genusId) {
            return ['success' => false, 'data' => []];
        }

        // 3. Buscar especies hermanas en iNaturalist
        $params = [
            'taxon_id' => $genusId, // Buscar dentro de este ancestro
            'rank' => 'species',
            'per_page' => 6, // 5 + 1 (por si sale el mismo)
            'order_by' => 'observations_count',
            'order' => 'desc',
            'photos' => 'true', 
        ];

        $relatedResult = $this->iNaturalistService->searchTaxon('', $params);
        
        if (!$relatedResult['success']) {
            return ['success' => false, 'data' => []];
        }

        // 4. Filtrar el taxón actual de los resultados
        $relatedTaxa = array_filter($relatedResult['data'], function($t) use ($taxonId) {
            return $t['id'] != $taxonId;
        });

        return [
            'success' => true,
            'data' => array_values(array_slice($relatedTaxa, 0, 5))
        ];
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
        Log::info('Procesando taxón API', ['scientific_name' => $scientificName]);

        if (!$scientificName) {
            Log::warning('Saltando taxón sin scientific_name', ['data_keys' => array_keys($taxonData)]);
            return null;
        }

        try {
            DB::beginTransaction();

            $taxon = Taxa::where('scientific_name', $scientificName)->first() ?? new Taxa(['scientific_name' => $scientificName]);
            $taxon->common_name = $taxonData['preferred_common_name'] ?? $this->getCommonNameFromApi($taxonData);

            // Ancestry (por ahora nulls; IDs en enriched_data)
            $ancestry = $this->extractAncestryFromApi($taxonData);
            $taxon->kingdom = $ancestry['kingdom'] ?? null;
            $taxon->phylum = $ancestry['phylum'] ?? null;
            $taxon->class = $ancestry['class'] ?? null;
            $taxon->order_name = $ancestry['order'] ?? null;
            $taxon->family = $ancestry['family'] ?? null;
            $taxon->genus = $ancestry['genus'] ?? null;
            $taxon->species = $ancestry['species'] ?? null;

            // Fetch status específico (place_id configurado)
            $colombiaStatus = $this->getEstablishmentStatus($taxonData['id'] ?? null);
            $flags = $this->mapEstablishmentMeans($colombiaStatus);
            $taxon->is_native = $flags['is_native'];
            $taxon->is_endemic = $flags['is_endemic'];

            $taxon->conservation_status = $this->mapConservationStatus($taxonData['conservation_statuses'] ?? $taxonData['conservation_status'] ?? null);
            $taxon->observation_count = ($taxon->observation_count ?? 0) + ($taxonData['observations_count'] ?? 0);
            $taxon->last_observed_at = now();
            $taxon->save();

            $this->updateOrCreateApiReference($taxon, $taxonData);

            DB::commit();
            Log::info('Taxón guardado exitosamente', ['id' => $taxon->id]);
            return $taxon->load('apiReferences');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error guardando taxón: ' . $e->getMessage(), ['scientific_name' => $scientificName]);
            return null;
        }
    }

    /**
     * Obtiene status de establecimiento para un taxón en el lugar configurado
     * Cachea por 24h para evitar llamadas repetidas.
     */
   public function getEstablishmentStatus(?string $taxonId): ?string
    {
        if (!$taxonId) {
            return null;
        }

        $cacheKey = "taxon_status_colombia_{$taxonId}";
        
        // Primero intentamos obtener del cache
        $cachedValue = Cache::get($cacheKey);
        
        // Si existe en cache y NO es null, retornarlo
        if ($cachedValue !== null) {
            Log::info('📦 Status desde cache', ['id' => $taxonId, 'status' => $cachedValue]);
            return $cachedValue;
        }

        try {
            // ✅ CRÍTICO: Usar preferred_place_id en lugar de place_id
            $response = $this->iNaturalistService->getTaxonById($taxonId, [
                'preferred_place_id' => config('services.inaturalist.preferred_place_id', 7196),  // Esto es lo que faltaba
                'locale' => 'es'
            ]);

            if ($response['success'] && isset($response['data'])) {
                $taxon = $response['data'];
                
                // Intentar extraer el status en orden de prioridad
                $status = null;
                
                // 1. Primero preferred_establishment_means (el más confiable)
                if (isset($taxon['preferred_establishment_means'])) {
                    $status = $taxon['preferred_establishment_means'];
                    Log::info('✅ Status de preferred_establishment_means', [
                        'id' => $taxonId, 
                        'status' => $status
                    ]);
                }
                // 2. Luego establishment_means array
                elseif (isset($taxon['establishment_means']) && is_array($taxon['establishment_means']) && !empty($taxon['establishment_means'])) {
                    $status = $taxon['establishment_means'][0]['establishment_means'] ?? 
                            $taxon['establishment_means'][0]['means'] ?? null;
                    Log::info('✅ Status de establishment_means array', [
                        'id' => $taxonId, 
                        'status' => $status
                    ]);
                }
                // 3. Flags booleanos como último recurso
                else {
                    if (!empty($taxon['endemic'])) {
                        $status = 'endemic';
                    } elseif (!empty($taxon['introduced'])) {
                        $status = 'introduced';
                    } elseif (!empty($taxon['native'])) {
                        $status = 'native';
                    }
                    
                    Log::info('⚠️ Status inferido de flags booleanos', [
                        'id' => $taxonId,
                        'status' => $status,
                        'endemic' => $taxon['endemic'] ?? false,
                        'introduced' => $taxon['introduced'] ?? false,
                        'native' => $taxon['native'] ?? false
                    ]);
                }

                // Solo cachear si encontramos un status válido
                if ($status !== null) {
                    Cache::put($cacheKey, $status, 86400); // 24 horas
                    return $status;
                }
                
                Log::warning('⚠️ No se pudo determinar status', [
                    'id' => $taxonId,
                    'available_fields' => array_keys($taxon)
                ]);
                return null;
            }
            
            Log::warning('❌ API no devolvió datos', ['id' => $taxonId]);
            return null;
            
        } catch (\Exception $e) {
            Log::error('💥 Error fetching status Colombia', [
                'id' => $taxonId, 
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
 * Extrae el status de establecimiento correctamente de los datos de la API
 * CORREGIDO: Ahora busca en los lugares correctos según la estructura real de iNaturalist
 */
public function extractEstablishmentStatusFromApiData(array $apiData): array
{
    Log::info('🔍 Extrayendo establishment status', [
        'has_preferred_establishment_means' => isset($apiData['preferred_establishment_means']),
        'preferred_establishment_means' => $apiData['preferred_establishment_means'] ?? null,
        'has_establishment_means' => isset($apiData['establishment_means']),
        'has_listed_taxa' => isset($apiData['listed_taxa']),
    ]);

    $status = null;
    
    // MÉTODO 1: preferred_establishment_means (el más directo)
    if (isset($apiData['preferred_establishment_means']) && !empty($apiData['preferred_establishment_means'])) {
        $status = $apiData['preferred_establishment_means'];
        Log::info('✅ Status de preferred_establishment_means', ['status' => $status]);
        return $this->mapEstablishmentMeans($status);
    }

    // MÉTODO 2: establishment_means (objeto con place)
    if (isset($apiData['establishment_means']) && is_array($apiData['establishment_means'])) {
        // Puede ser un objeto simple o array
        if (isset($apiData['establishment_means']['establishment_means'])) {
            // Es un objeto: { "establishment_means": "endemic", "place": {...} }
            $status = $apiData['establishment_means']['establishment_means'];
            Log::info('✅ Status de establishment_means (objeto)', ['status' => $status]);
            return $this->mapEstablishmentMeans($status);
        } elseif (isset($apiData['establishment_means'][0]['establishment_means'])) {
            // Es un array: [{ "establishment_means": "endemic", "place": {...} }]
            $status = $apiData['establishment_means'][0]['establishment_means'];
            Log::info('✅ Status de establishment_means (array)', ['status' => $status]);
            return $this->mapEstablishmentMeans($status);
        }
    }

    // MÉTODO 3: listed_taxa (buscar place_id configurado)
    if (isset($apiData['listed_taxa']) && is_array($apiData['listed_taxa'])) {
        $targetPlaceId = config('services.inaturalist.preferred_place_id', 7196);
        foreach ($apiData['listed_taxa'] as $listedTaxon) {
            if (isset($listedTaxon['place']['id']) && $listedTaxon['place']['id'] == $targetPlaceId) {
                $status = $listedTaxon['establishment_means'] ?? null;
                if ($status) {
                    Log::info('✅ Status de listed_taxa (Colombia)', ['status' => $status]);
                    return $this->mapEstablishmentMeans($status);
                }
            }
        }
    }

    // MÉTODO 4: Flags booleanos (fallback)
    if (!empty($apiData['endemic'])) {
        $status = 'endemic';
        Log::info('⚠️ Status inferido de flag endemic', ['status' => $status]);
        return ['is_native' => true, 'is_endemic' => true, 'status' => $status];
    }
    
    if (!empty($apiData['introduced'])) {
        $status = 'introduced';
        Log::info('⚠️ Status inferido de flag introduced', ['status' => $status]);
        return ['is_native' => false, 'is_endemic' => false, 'status' => $status];
    }
    
    if (!empty($apiData['native'])) {
        $status = 'native';
        Log::info('⚠️ Status inferido de flag native', ['status' => $status]);
        return ['is_native' => true, 'is_endemic' => false, 'status' => $status];
    }

    // No se pudo determinar
    Log::warning('❌ No se pudo determinar establishment status', [
        'available_keys' => array_keys($apiData)
    ]);
    
    return ['is_native' => false, 'is_endemic' => false, 'status' => 'unknown'];
}

/**
 * Mapea status de establecimiento a flags locales
 */
public function mapEstablishmentMeans(?string $status): array
{
    $statusLower = strtolower($status ?? '');
    
    $map = [
        'endemic' => ['is_native' => true, 'is_endemic' => true, 'status' => 'endemic'],
        'native' => ['is_native' => true, 'is_endemic' => false, 'status' => 'native'],
        'introduced' => ['is_native' => false, 'is_endemic' => false, 'status' => 'introduced'],
    ];
    
    return $map[$statusLower] ?? ['is_native' => false, 'is_endemic' => false, 'status' => 'unknown'];
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
     * Mapea status de conservación a formato ENUM de tu base de datos
     * CORREGIDO: iNaturalist devuelve directamente los códigos IUCN (LC, VU, EN, etc.)
     * 
     * @param mixed $apiStatus Puede ser string, array o null
     * @return string El código IUCN o 'NE' si no se encuentra
     */
    protected function mapConservationStatus($apiStatus): string
    {
        // Si es null, retornar 'NE' (No Evaluado)
        if ($apiStatus === null) {
            return 'NE';
        }
        
        // Si es un array (conservation_statuses de iNaturalist)
        if (is_array($apiStatus)) {
            // Si está vacío, retornar 'NE'
            if (empty($apiStatus)) {
                return 'NE';
            }
            
            // Tomar el primer status (generalmente IUCN global)
            $firstStatus = $apiStatus[0] ?? null;
            
            if ($firstStatus && isset($firstStatus['status'])) {
                $statusCode = $firstStatus['status'];
                Log::info('📊 Status de conservación extraído', [
                    'raw' => $firstStatus,
                    'status_code' => $statusCode,
                    'authority' => $firstStatus['authority'] ?? 'unknown'
                ]);
                
                // Validar que sea un código IUCN válido
                if ($this->isValidIUCNStatus($statusCode)) {
                    return $statusCode;
                }
            }
            
            return 'NE';
        }
        
        // Si es un string directo, validar y retornar
        if (is_string($apiStatus)) {
            // Normalizar a mayúsculas
            $statusUpper = strtoupper($apiStatus);
            
            // Si ya es un código IUCN válido, retornarlo
            if ($this->isValidIUCNStatus($statusUpper)) {
                return $statusUpper;
            }
            
            // Si está en formato de texto completo en inglés, convertir
            $textToCodeMap = [
                'least_concern' => 'LC',
                'least concern' => 'LC',
                'near_threatened' => 'NT',
                'near threatened' => 'NT',
                'vulnerable' => 'VU',
                'endangered' => 'EN',
                'critically_endangered' => 'CR',
                'critically endangered' => 'CR',
                'extinct_in_the_wild' => 'EW',
                'extinct in the wild' => 'EW',
                'extinct' => 'EX',
                'data_deficient' => 'DD',
                'data deficient' => 'DD',
                'not_evaluated' => 'NE',
                'not evaluated' => 'NE',
            ];
            
            $statusLower = strtolower($apiStatus);
            if (isset($textToCodeMap[$statusLower])) {
                return $textToCodeMap[$statusLower];
            }
        }
        
        // Si no se pudo determinar, retornar 'NE'
        Log::warning('⚠️ No se pudo mapear conservation status', [
            'input' => $apiStatus,
            'type' => gettype($apiStatus)
        ]);
        
        return 'NE';
    }

    /**
     * Valida si un código es un status IUCN válido
     * 
     * @param string $code
     * @return bool
     */
    protected function isValidIUCNStatus(string $code): bool
    {
        $validCodes = ['LC', 'NT', 'VU', 'EN', 'CR', 'EW', 'EX', 'DD', 'NE'];
        return in_array(strtoupper($code), $validCodes);
    }

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
     * Obtiene especies de Colombia para exploración
     * Cachea el resultado por 30 minutos para optimizar rendimiento
     *
     * @param array $filters
     * @return array
     */
    public function getColombiaSpecies(array $filters = []): array
    {
        // Crear clave de caché basada en los filtros
        $cacheKey = 'colombia_species_' . md5(json_encode($filters));

        return Cache::remember($cacheKey, 1800, function () use ($filters) { // 30 minutos
            try {
                // Buscar especies populares de Colombia con fotos
                // Aseguramos que place_id esté presente para filtrar por Colombia
                // Aseguramos que place_id esté presente para filtrar por Colombia
                $placeId = config('services.inaturalist.place_id', 7196);
                
                $apiFilters = array_merge($filters, [
                    'place_id' => $placeId,
                    'order_by' => 'observations_count',
                    'order' => 'desc',
                    'has' => ['photos'],
                    'rank' => 'species',
                    'is_active' => 'true',
                    'per_page' => $filters['per_page'] ?? 24, // Agnostic default, controller/frontend controls it
                    'page' => $filters['page'] ?? 1,
                ]);

                // Force place_id again in case $filters overwrote it
                $apiFilters['place_id'] = $placeId;

                // Mapear filtros de frontend a API de iNaturalist
                if (isset($filters['native']) && $filters['native']) $apiFilters['native'] = 'true';
                if (isset($filters['endemic']) && $filters['endemic']) $apiFilters['endemic'] = 'true';
                if (isset($filters['threatened']) && $filters['threatened']) $apiFilters['threatened'] = 'true';
                // Map common class names to their iNaturalist Taxon IDs for better filtering
                $classMap = [
                    'Aves' => 3,
                    'Mammalia' => 40151,
                    'Reptilia' => 26036,
                    'Amphibia' => 20978,
                    'Insecta' => 47158,
                    'Plantae' => 47126,
                    'Fungi' => 47170
                ];

                if (isset($filters['rank']) && $filters['rank'] !== 'Todas') {
                     if (isset($classMap[$filters['rank']])) {
                         $apiFilters['taxon_id'] = $classMap[$filters['rank']];
                     } else {
                         // Fallback for unmapped or generic search
                         if (empty($filters['q'])) {
                             $apiFilters['q'] = $filters['rank']; // Only use rank as query if no q is present
                         }
                     }
                }

                $query = $filters['q'] ?? '';

                // Use getSpeciesCounts (observations/species_counts) instead of searchTaxon (taxa)
                // This correctly filters regular species lists by place_id.
                // searchTaxon ignores place_id for filtering list (only used for establishment status).
                
                // If we have a generic text query ('q'), we might still want searchTaxon IF we can't search by name in species_counts.
                // But species_counts doesn't support 'q' well for name search.
                // However, for "Exploring", species_counts is the correct endpoint.
                
                // If user is searching by name (`q` parameter is present and not empty), we might have a tradeoff.
                // iNat /observations/species_counts DOES NOT support `q` param for partial text search on species names.
                // It supports `taxon_name` or `taxon_id`.
                
                // HYBRID APPROACH:
                // 1. If 'q' is empty (Browsing/Exploring), use getSpeciesCounts -> returns top species in Colombia.
                // 2. If 'q' is present, use searchTaxon (Global search, but filtered results are hard).
                //    Wait, we want to search "Parrot in Colombia".
                //    The correct way is: searchTaxon first to get Candidate Taxa IDs, then filter those by presence in Colombia? That's too heavy.
                //    OR use /observations?q=parrot&place_id=7196&per_page=0&return_bounds=true... no.
                
                // Let's stick to getSpeciesCounts for Browsing.
                // For Search ('q' is present), we rely on `searchTaxon` which is what we used before.
                // BUT user says "Global species appear".
                // If 'q' is empty, we MUST use getSpeciesCounts to show top Colombia species.
                
                if (empty($filters['q'])) {
                    $apiResult = $this->iNaturalistService->getSpeciesCounts($apiFilters);
                } else {
                    // Search is active. iNat /taxa endpoint (searchTaxon) doesn't filter by place.
                    // To search species IN Colombia, we technically should search:
                    // /observations/species_counts?place_id=7196&q=Parrot ?? No, q not supported.
                    // We can use `taxon_name` param in species_counts!
                    
                    if (isset($apiFilters['q'])) {
                        $apiFilters['taxon_name'] = $apiFilters['q'];
                        // unset($apiFilters['q']); // Keep it if method needs it for logging, but mapped above.
                    }
                    
                    $apiResult = $this->iNaturalistService->getSpeciesCounts($apiFilters);
                }

                if (!$apiResult['success']) {
                    Log::error('Error al obtener especies de Colombia', [
                        'filters' => $filters,
                        'error' => $apiResult['error'] ?? 'Error desconocido'
                    ]);

                    return [
                        'success' => false,
                        'error' => $apiResult['error'] ?? ['message' => 'Error al obtener especies de Colombia'],
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

                // Enriquecer con datos unidos
                $formattedTaxa = collect($savedTaxa)->map->enriched_data->toArray();

                $pagination = $apiResult['pagination'] ?? [
                    'total' => count($formattedTaxa),
                    'per_page' => $filters['per_page'] ?? 24,
                    'page' => $filters['page'] ?? 1,
                ];
                
                // Calculate last_page/total_pages
                $perPage = $pagination['per_page'] ?? 24;
                $total = $pagination['total'] ?? 0;
                $pagination['last_page'] = $perPage > 0 ? (int)ceil($total / $perPage) : 1;
                $pagination['current_page'] = $pagination['page'] ?? 1;

                return [
                    'success' => true,
                    'data' => $formattedTaxa,
                    'pagination' => $pagination,
                    'cached' => true, // Indicar que viene del caché del servicio
                    'source' => 'api',
                ];

            } catch (\Exception $e) {
                Log::error('Excepción al obtener especies de Colombia: ' . $e->getMessage(), [
                    'filters' => $filters,
                    'trace' => $e->getTraceAsString()
                ]);

                return [
                    'success' => false,
                    'error' => ['message' => 'Error inesperado al obtener especies de Colombia: ' . $e->getMessage()],
                    'source' => 'api',
                ];
            }
        });
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