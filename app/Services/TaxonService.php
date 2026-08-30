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
use Illuminate\Support\Arr;

class TaxonService
{
    /**
     * @var INaturalistService
     */
    protected $iNaturalistService;

    /**
     * @var Api\GbifService
     */
    protected $gbifService;

    protected $conservationStatusResolver;
    
    /**
     * @param INaturalistService $iNaturalistService
     * @param Api\GbifService $gbifService
     */
    public function __construct(
        INaturalistService $iNaturalistService,
        Api\GbifService $gbifService,
        ConservationStatusResolver $conservationStatusResolver
    ) {
        $this->iNaturalistService = $iNaturalistService;
        $this->gbifService = $gbifService;
        $this->conservationStatusResolver = $conservationStatusResolver;
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
        // Si la consulta está vacía o es 'all', listamos todas las especies locales aplicando filtros
        if (empty(trim($query)) || $query === 'all') {
            return $this->getSpeciesNearLocation($filters);
        }

        // Buscamos en la base de datos local
        $localResults = $this->searchLocalTaxa($query, $filters);
        
        // Enriquecer los resultados locales al vuelo
        $formattedLocal = [];
        foreach ($localResults['data'] as $taxon) {
            if ($taxon instanceof Taxa) {
                $taxon = $this->ensureTaxonIsEnriched($taxon);
                $formattedLocal[] = $taxon->enriched_data;
            } else {
                $formattedLocal[] = $taxon;
            }
        }
        
        $localResults['data'] = $formattedLocal;
        $localResults['source'] = 'local_enriched_search';
        $localResults['cached'] = true;

        return $localResults;
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
        $localTaxon = null;

        // El ID de la ruta siempre es local. Incluso al forzar una actualización
        // primero debemos resolver el registro local y su ID externo real.
        {
            // 1. Intentar por ID Local (PK)
            $localTaxon = Taxa::with(['apiReferences'])->find($id);

            // 2. Si no encuentra por ID Local, intentar por ID Externo (iNaturalist)
            // Esto soluciona el problema de IDs retornados por la búsqueda de API
            if (!$localTaxon) {
                Log::info('🔍 Buscando taxón por external_id (fallback)', ['id' => $id]);
                $reference = \App\Models\TaxonApiReference::where('external_id', $id)
                    ->where('api_source', 'inaturalist')
                    ->first();
                
                if ($reference) {
                    $localTaxon = Taxa::with(['apiReferences'])->find($reference->taxon_id);
                    if ($localTaxon) {
                         Log::info('✅ Taxón local encontrado por external_id', [
                             'external_id' => $id, 
                             'local_id' => $localTaxon->id
                         ]);
                    }
                }
            }
            
            if ($localTaxon) {
                // VERIFICACIÓN DE CALIDAD DE DATOS (Auto-Upgrade)
                // Si la data local es "lite" (viene de una lista y no tiene galería/detalles),
                // forzamos una actualización para obtener la data completa.
                $enrichedData = $localTaxon->enriched_data;
                
                $hasGallery = !empty($enrichedData['gallery']);
                $hasAncestors = !empty($enrichedData['ancestors']);
                $hasLocalTaxonomy = !empty($localTaxon->kingdom)
                    && !empty($localTaxon->family)
                    && !empty($localTaxon->genus);
                $hasSummary = !empty($enrichedData['wikipedia_summary']);

                // CRITERIO DE COMPLETITUD:
                // Debe tener Ancestros (info taxonómica) Y (Galería O Resumen).
                // Si falta info taxonómica O (falta foto y falta texto), consideramos incompleto.
                $isComplete = ($hasAncestors || $hasLocalTaxonomy) && ($hasGallery || $hasSummary);

                Log::info('🧐 Verificando completitud de taxón local', [
                    'id' => $localTaxon->id, // Usar ID real del objeto
                    'searched_id' => $id,
                    'has_gallery' => $hasGallery, 
                    'gallery_count' => count($enrichedData['gallery'] ?? []),
                    'has_ancestors' => $hasAncestors,
                    'has_local_taxonomy' => $hasLocalTaxonomy,
                    'has_summary' => $hasSummary,
                    'is_complete' => $isComplete
                ]);

                // Si parece completo, devolvemos la data local.
                if (!$forceRefresh && $isComplete) {
                    return [
                        'success' => true,
                        'data' => $localTaxon,
                        'source' => 'local',
                    ];
                }
                
                // Si llegamos aquí, falta información.
                Log::info('🔄 Actualizando taxón incompleto', [
                    'id' => $localTaxon->id, 
                    'missing' => [
                        'gallery' => !$hasGallery,
                        'ancestors' => !$hasAncestors,
                        'summary' => !$hasSummary
                    ]
                ]);
            }
        }
        
        // PREPARAR ID PARA LA API
        // El $id que recibimos es el ID LOCAL (PK de la tabla taxa).
        // Para consultar a iNaturalist necesitamos el EXTERNAL ID (guardado en api_references).
        $externalId = null;
        
        if (isset($localTaxon) && $localTaxon) {
            $ref = $localTaxon->apiReferences->firstWhere('api_source', 'inaturalist');
            $externalId = $ref?->external_id ?: $localTaxon->inat_taxon_id;
        }

        // Si no tenemos external ID (caso raro o primer acceso si lógica fuera mixta),
        // no podemos consultar por ID directo a menos que asumamos que $id ES el external_id (peligroso).
        // En este flujo, getTaxonById asume ID local. Si no hay external ID, quizás debamos buscar por nombre.
        
        $apiIdToQuery = $externalId;

        if (!$apiIdToQuery) {
            // Fallback: Si no hay referencia, y estamos forzando, quizás el usuario pasó un ID externo?
            // O simplemente fallamos.
            if (!$localTaxon) {
                // Si no existe localmente, asumimos que $id PODRÍA ser un external ID (si la ruta lo permite)
                // Pero lo seguro es que esta función espera ID local.
                $apiIdToQuery = (string)$id; 
            } else {
                Log::error('❌ No se puede actualizar taxón: falta external_id', ['id' => $id]);
                 return [
                    'success' => true, // Retornar lo que tenemos aunque sea viejo
                    'data' => $localTaxon,
                    'source' => 'local_incomplete',
                ];
            }
        }

        Log::info('🌍 Consultando API iNaturalist', ['local_id' => $id, 'external_id' => $apiIdToQuery]);
        
        // Si no se encontró localmente o es incompleto, buscamos en la API
        $apiResult = $this->iNaturalistService->getTaxonById($apiIdToQuery);
        
        if (!$apiResult['success']) {
            // Si falla la API pero teníamos data local (aunque incompleta), devolvemos esa como fallback
            if (isset($localTaxon) && $localTaxon) {
                Log::warning('⚠️ API falló, usando data local incompleta como fallback', [
                    'id' => $id,
                    'error' => $apiResult['error'] ?? 'Unknown error'
                ]);
                return [
                    'success' => true,
                    'data' => $localTaxon,
                    'source' => 'local_fallback',
                ];
            }

            return [
                'success' => false,
                'error' => $apiResult['error'] ?? ['message' => 'Error al obtener el taxón de la API'],
                'source' => 'api',
            ];
        }
        
        // Procesamos y guardamos el taxón de la API (Esto actualiza la data existente)
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
     * Obtiene observaciones de un taxón específico desde la BD local.
     *
     * @param int $taxonId
     * @param array $params
     * @return array
     */
    public function getTaxonObservations(int $taxonId, array $params = []): array
    {
        // Verificar que el taxón existe localmente
        $taxon = Taxa::find($taxonId);
        if (! $taxon) {
            return [
                'success' => false,
                'error'   => ['message' => 'Taxón no encontrado', 'code' => 404],
            ];
        }

        $perPage = $params['per_page'] ?? 15;
        $page    = $params['page'] ?? 1;

        $query = Observation::with(['user:id,full_name,avatar', 'photos'])
            ->where('taxon_id', $taxonId)
            ->where('is_public', true)
            ->orderByDesc('observed_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'success'    => true,
            'data'       => $paginator->items(),
            'pagination' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
            'cached' => false,
            'source' => 'local',
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
        $baseTaxon = Taxa::with('apiReferences')->find($taxonId);

        if (!$baseTaxon) {
            return ['success' => false, 'error' => 'Taxón base no encontrado'];
        }

        $related = collect();

        if ($baseTaxon->genus) {
            $related = Taxa::with('apiReferences')
                ->where('genus', $baseTaxon->genus)
                ->whereKeyNot($baseTaxon->id)
                ->orderByDesc('observation_count')
                ->limit(3)
                ->get();
        }

        // Completar con especies de la misma familia cuando el género no alcanza tres resultados.
        if ($related->count() < 3 && $baseTaxon->family) {
            $familyRelated = Taxa::with('apiReferences')
                ->where('family', $baseTaxon->family)
                ->whereKeyNot($baseTaxon->id)
                ->whereNotIn('id', $related->pluck('id'))
                ->orderByDesc('observation_count')
                ->limit(3 - $related->count())
                ->get();

            $related = $related->concat($familyRelated);
        }

        return [
            'success' => true,
            'data' => $related->values()->all(),
            'source' => 'local_taxonomy',
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
            $commonName = $taxonData['preferred_common_name'] ?? $this->getCommonNameFromApi($taxonData);
            // El nombre del catálogo local es canónico. iNaturalist solo completa
            // este campo cuando todavía no tenemos un nombre común registrado.
            if ($commonName && empty($taxon->common_name)) {
                $taxon->common_name = $commonName;
            }

            // Ancestry (por ahora nulls; IDs en enriched_data)
            $ancestry = $this->extractAncestryFromApi($taxonData);
            foreach ([
                'kingdom' => 'kingdom',
                'phylum' => 'phylum',
                'class' => 'class',
                'order' => 'order_name',
                'family' => 'family',
                'genus' => 'genus',
                'species' => 'species',
            ] as $source => $attribute) {
                if (!empty($ancestry[$source])) {
                    $taxon->{$attribute} = $ancestry[$source];
                }
            }

            // Determinar status de establecimiento usando el extractor robusto
            // (incluye preferred_establishment_means, establishment_means, listed_taxa y flags booleanos)
            $flags = $this->extractEstablishmentStatusFromApiData($taxonData);
            if (($flags['status'] ?? 'unknown') !== 'unknown') {
                $taxon->is_native = $flags['is_native'];
                $taxon->is_endemic = $flags['is_endemic'];
                $taxon->is_introduced = $flags['is_introduced'] ?? false;
            }

            $conservation = $this->conservationStatusResolver->resolve(
                $taxonData['conservation_statuses'] ?? $taxonData['conservation_status'] ?? null,
                (int) config('services.inaturalist.preferred_place_id', 7196)
            );
            $taxon->conservation_status_synced_at = now();

            if ($conservation['code']) {
                $taxon->conservation_status = $conservation['code'];
                $taxon->conservation_status_source = 'inaturalist';
                $taxon->conservation_status_scope = $conservation['scope'];
                $taxon->conservation_status_authority = $conservation['authority'];
                $taxon->conservation_status_url = $conservation['url'];
            } elseif (array_key_exists('conservation_status', $taxonData)
                && ($taxon->conservation_status === 'NE' || $taxon->conservation_status_source === 'inaturalist')) {
                // iNaturalist sin evaluación no equivale a la categoría formal NE.
                $taxon->conservation_status = null;
                $taxon->conservation_status_source = 'inaturalist';
                $taxon->conservation_status_scope = null;
                $taxon->conservation_status_authority = null;
                $taxon->conservation_status_url = null;
            } elseif ($taxon->conservation_status && !$taxon->conservation_status_source) {
                $taxon->conservation_status_source = 'legacy';
            }
            $taxon->observation_count = max(
                (int) ($taxon->observation_count ?? 0),
                (int) ($taxonData['observations_count'] ?? 0)
            );
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
     * Guarda un taxón encontrado directamente por iNaturalist.
     * Se usa cuando el catálogo geográfico de GBIF no tiene una coincidencia.
     */
    public function enrichFromINaturalistId(string $externalId): ?Taxa
    {
        $response = $this->iNaturalistService->getTaxonById($externalId);

        if (!($response['success'] ?? false) || empty($response['data'])) {
            return null;
        }

        return $this->createOrUpdateTaxonFromApiData($response['data']);
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
        return ['is_native' => true, 'is_endemic' => true, 'is_introduced' => false, 'status' => $status];
    }
    
    if (!empty($apiData['introduced'])) {
        $status = 'introduced';
        Log::info('⚠️ Status inferido de flag introduced', ['status' => $status]);
        return ['is_native' => false, 'is_endemic' => false, 'is_introduced' => true, 'status' => $status];
    }
    
    if (!empty($apiData['native'])) {
        $status = 'native';
        Log::info('⚠️ Status inferido de flag native', ['status' => $status]);
        return ['is_native' => true, 'is_endemic' => false, 'is_introduced' => false, 'status' => $status];
    }

    // No se pudo determinar
    Log::warning('❌ No se pudo determinar establishment status', [
        'available_keys' => array_keys($apiData)
    ]);
    
    return ['is_native' => false, 'is_endemic' => false, 'is_introduced' => false, 'status' => 'unknown'];
}

/**
 * Mapea status de establecimiento a flags locales
 */
public function mapEstablishmentMeans(?string $status): array
{
    $statusLower = strtolower($status ?? '');
    
    $map = [
        'endemic' => ['is_native' => true, 'is_endemic' => true, 'is_introduced' => false, 'status' => 'endemic'],
        'native' => ['is_native' => true, 'is_endemic' => false, 'is_introduced' => false, 'status' => 'native'],
        'introduced' => ['is_native' => false, 'is_endemic' => false, 'is_introduced' => true, 'status' => 'introduced'],
    ];
    
    return $map[$statusLower] ?? ['is_native' => false, 'is_endemic' => false, 'is_introduced' => false, 'status' => 'unknown'];
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
     * Asegura que un taxón local esté enriquecido con los datos completos de iNaturalist
     *
     * @param Taxa $taxon
     * @return Taxa
     */
    public function ensureTaxonIsEnriched(Taxa $taxon): Taxa
    {
        $ref = $taxon->apiReferences->firstWhere('api_source', 'inaturalist');
        
        // Si no tiene referencia o la referencia no tiene la data de iNaturalist poblada
        if (!$ref || empty($ref->data)) {
            $externalId = $ref ? $ref->external_id : null;
            
            // Si no tiene external_id, lo buscamos en iNaturalist usando su nombre científico
            if (!$externalId) {
                Log::info("🔍 Buscando external_id en iNaturalist para: {$taxon->scientific_name}");
                $searchResponse = $this->iNaturalistService->searchTaxon($taxon->scientific_name, ['per_page' => 1]);
                if ($searchResponse['success'] && !empty($searchResponse['data'])) {
                    $apiData = $searchResponse['data'][0];
                    $externalId = $apiData['id'];
                }
            }

            if ($externalId) {
                Log::info("🌍 Enriqueciendo taxón local #{$taxon->id} con iNaturalist ID: {$externalId}");
                $apiResult = $this->iNaturalistService->getTaxonById($externalId);
                if ($apiResult['success'] && !empty($apiResult['data'])) {
                    $updatedTaxon = $this->createOrUpdateTaxonFromApiData($apiResult['data']);
                    if ($updatedTaxon) {
                        return $updatedTaxon;
                    }
                }
            }

            return $taxon;
        }

        // Si ya tenemos datos de API cacheados, también debemos persistirlos en la tabla local
        // para mantener actualizados los campos `conservation_status`, `is_native` y `is_endemic`.
        if (!empty($ref->data)) {
            $updatedTaxon = $this->createOrUpdateTaxonFromApiData($ref->data);
            if ($updatedTaxon) {
                return $updatedTaxon;
            }
        }

        return $taxon;
    }

    /**
     * Obtiene especies locales enriquecidas con iNaturalist
     * Optimizada para retornar el catálogo local aplicando filtros del frontend.
     */
    public function getSpeciesNearLocation(array $filters = []): array
    {
        try {
            Log::info('🔍 Filtros locales recibidos en getSpeciesNearLocation', $filters);

            // 1. Extraer Params
            $perPage = (int)($filters['per_page'] ?? 24);
            $page = (int)($filters['page'] ?? 1);
            $orderBy = $filters['order_by'] ?? 'observations_count';
            $q = $filters['q'] ?? null;
            $lat = (float)($filters['latitude'] ?? null);
            $lon = (float)($filters['longitude'] ?? null);
            $radiusKm = (float)($filters['radius_km'] ?? 50);

            // 2. Consulta base sobre el catálogo local de taxa (solo enriquecidas)
            $query = Taxa::with(['apiReferences'])
                ->where('sync_status', 'synced'); // Solo mostrar especies que se sincronizaron exitosamente

            // Filtro por término de búsqueda (q)
            if (!empty($q)) {
                $query->where(function($qBuilder) use ($q) {
                    $qBuilder->where('scientific_name', 'like', "%{$q}%")
                             ->orWhere('common_name', 'like', "%{$q}%");
                });
            }

            // Filtro por grupo icónico (iconic_taxa)
            if (!empty($filters['iconic_taxa'])) {
                $iconic = $filters['iconic_taxa'];
                $query->where(function($qBuilder) use ($iconic) {
                    $qBuilder->where('class', $iconic)
                             ->orWhere('phylum', $iconic)
                             ->orWhere('kingdom', $iconic);
                });
            }

            // Filtro por nativas/endémicas
            if (isset($filters['native']) && $filters['native'] === true) {
                $query->where('is_native', true);
            }
            if (isset($filters['endemic']) && $filters['endemic'] === true) {
                $query->where('is_endemic', true);
            }

            // Filtro por amenazadas (threatened)
            if (isset($filters['threatened']) && $filters['threatened'] === true) {
                $query->whereIn('conservation_status', ['VU', 'EN', 'CR', 'EW', 'EX']);
            }

            // Ordenamiento
            if ($orderBy === 'random') {
                $query->inRandomOrder();
            } elseif ($orderBy === 'observed_on' || $orderBy === 'last_observed_at') {
                $query->orderByDesc('last_observed_at');
            } else {
                $query->orderByDesc('observation_count');
            }

            // Paginación
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);
            $taxa = $paginator->items();
            $formattedTaxa = [];

            // 3. Enriquecimiento al vuelo e inserción de datos iNaturalist en BD local si no existen
            foreach ($taxa as $taxon) {
                $taxon = $this->ensureTaxonIsEnriched($taxon);
                $formattedTaxa[] = $taxon->enriched_data;
            }

            // 4. Cache miss: Si no hay especies en esta zona, dispara sincronización
            if ($paginator->total() === 0 && $lat !== null && $lon !== null) {
                Log::info("📍 Cache miss en zona nueva, encolando sincronización", [
                    'latitude' => $lat,
                    'longitude' => $lon,
                    'radius_km' => $radiusKm,
                ]);
                
                \App\Jobs\SyncRegionOccurrencesJob::dispatch($lat, $lon, $radiusKm)
                    ->onQueue('species-sync');
                
                // Responder honestamente: "Todavía cargando datos de tu zona"
                return [
                    'success' => true,
                    'data' => [],
                    'pagination' => [
                        'total' => 0,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => 1,
                        'from' => null,
                        'to' => null,
                    ],
                    'source' => 'local_cache',
                    'cached' => true,
                    'loading' => true,
                    'message' => 'Estamos cargando la información de biodiversidad en tu zona. Por favor, intenta de nuevo en un momento.',
                ];
            }

            return [
                'success' => true,
                'data' => $formattedTaxa,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
                'source' => 'local_enriched_hybrid',
                'cached' => true
            ];

        } catch (\Exception $e) {
            Log::error('Error en getSpeciesNearLocation local: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error' => ['message' => 'Error al obtener la lista local de especies: ' . $e->getMessage()]
            ];
        }
    }
    
    /**
     * Obtiene especies de Colombia para exploración
     * Cachea el resultado por 30 minutos para optimizar rendimiento
     *
     * @param array $filters
     * @return array
     */

    /**
        }
        
        return $result;
    }

    /**
     * Hybrid Approach: GBIF (List) -> iNaturalist (Enrichment)
     * 1. Get species list from GBIF based on location filters.
     * 2. Check local DB for known data.
     * 3. Enrich missing data from iNaturalist (Batch/Parallel).
     * 4. Filter out species without photos (Strict Requirement).
     */
    public function getColombiaSpecies(array $filters = []): array
    {
        $start = microtime(true);
        Log::info('🚀 Arrancando Hybrid Search', ['filters' => $filters]);

        try {
            // FAST PATH: If filters require establishment/threatened flags, use iNaturalist species_counts
            $wantsNative = !empty($filters['native']);
            $wantsEndemic = !empty($filters['endemic']);
            $wantsThreatened = !empty($filters['threatened']);

            if ($wantsNative || $wantsEndemic || $wantsThreatened) {
                $inatParams = [
                    'per_page' => $filters['per_page'] ?? 24,
                    'page' => $filters['page'] ?? 1,
                    'place_id' => config('services.inaturalist.place_id', 7196),
                    // Keep photos and verifiable defaults from INaturalistService->getSpeciesCounts
                ];

                $countsResult = $this->iNaturalistService->getSpeciesCounts($inatParams);

                if (!$countsResult['success']) {
                    return [
                        'success' => false,
                        'error' => $countsResult['error'] ?? ['message' => 'Error al obtener species_counts de iNaturalist'],
                        'source' => 'api'
                    ];
                }

                $items = $countsResult['data'] ?? [];

                // Server-side post-filters based on normalized flags
                $filtered = collect($items)->filter(function ($t) use ($wantsNative, $wantsEndemic, $wantsThreatened) {
                    // threatened: conservation_status IUCN CR/EN/VU
                    $isThreatened = false;
                    if (!empty($t['conservation_status']['status'])) {
                        $status = strtoupper($t['conservation_status']['status']);
                        $isThreatened = in_array($status, ['CR', 'EN', 'VU']);
                    }

                    if ($wantsNative && empty($t['native'])) return false;
                    if ($wantsEndemic && empty($t['endemic'])) return false;
                    if ($wantsThreatened && !$isThreatened) return false;
                    return true;
                })->values()->all();

                // Light payload for list: drop heavy arrays if any
                $light = collect($filtered)->map(function ($taxon) {
                    // Ensure minimal shape for list cards
                    unset($taxon['gallery']);
                    unset($taxon['ancestors']);
                    unset($taxon['conservation_statuses']);
                    return $taxon;
                })->toArray();

                return [
                    'success' => true,
                    'data' => $light,
                    'pagination' => $countsResult['pagination'] ?? [
                        'page' => $filters['page'] ?? 1,
                        'per_page' => $filters['per_page'] ?? 24,
                        'total' => count($light),
                    ],
                    'cached' => $countsResult['cached'] ?? false,
                    'source' => 'inat_species_counts',
                ];
            }

            // STEP 1: Get raw list from GBIF
            // This handles Country, Dept, Municipality filters natively
            $gbifResult = $this->gbifService->getSpeciesList($filters);

            if (!$gbifResult['success'] || empty($gbifResult['data'])) {
                Log::info('⚠️ GBIF returned no results', ['filters' => $filters]);
                return [
                    'success' => true,
                    'data' => [],
                    'pagination' => ['total' => 0, 'page' => 1]
                ];
            }

            $speciesList = $gbifResult['data']; // Array of ['scientific_name' => '...', 'source_id' => '...']
            $namesToEnrich = collect($speciesList)->pluck('scientific_name')->unique()->values()->toArray();

            // STEP 2: Enrichment Strategy (Avoid N+1)
            // Strategy: Check Local DB -> If missing, Search iNat ONLY for matches
            
            $finalTaxa = [];
            $missingInLocal = [];

            // 2a. Check Local DB with eager loading
            $localTaxa = Taxa::with('apiReferences')
                ->whereIn('scientific_name', $namesToEnrich)
                ->get()
                ->keyBy('scientific_name');

            foreach ($speciesList as $sp) {
                $name = $sp['scientific_name'];
                if ($localTaxa->has($name)) {
                    $taxon = $localTaxa->get($name);
                    
                    // Optimization: If enrichment is NOT requested, manually build light payload
                    // avoiding the heavy getEnrichedDataAttribute accessor
                    if (empty($filters['enrich']) || $filters['enrich'] === 'false' || $filters['enrich'] === '0') {
                        // Check for photo manually from relation (Eager loaded 'apiReferences')
                        // We need to parse the 'data' JSON column of the pivot/reference
                        $ref = $taxon->apiReferences->firstWhere('api_source', 'inaturalist');
                        $photoUrl = null;
                        
                        // Try to get photo from ref data without full enrichment logic
                        if ($ref && $ref->data && !empty($ref->data['default_photo'])) {
                            $photo = $ref->data['default_photo'];
                            $photoUrl = is_array($photo)
                                ? ($photo['medium_url'] ?? $photo['url'] ?? $photo['original_url'] ?? null)
                                : null;
                        } elseif ($taxon->default_photo) { 
                             // Fallback to model attribute if exists (legacy)
                             $photoUrl = $taxon->default_photo;
                        }

                        $finalTaxa[] = [
                            'id' => $taxon->id,
                            'scientific_name' => $taxon->scientific_name,
                            'common_name' => $taxon->common_name,
                            'default_photo' => $photoUrl ? ['url' => $photoUrl] : null,
                            // Minimal fields for card
                        ];
                    } else {
                        // Original Heavy Path
                        $enrichedData = $taxon->enriched_data;
                        if (!empty($enrichedData['default_photo']['url'] ?? null)) {
                             $finalTaxa[] = $enrichedData;
                        }
                    }
                } else {
                    $missingInLocal[$name] = $sp; // Queue for iNat check
                }
            }

            // 2b. Batch Process Missing Species via iNaturalist
            // Issue: iNat API doesn't have a "search by list of names" endpoint easily.
            // We have to iterate, BUT we can do it smarter or limit the "new" ones per page.
            
            // Limit enrichment to avoid timeouts on fresh searches
            $limitNew = 10; 
            $processed = 0;

            foreach ($missingInLocal as $name => $gbifData) {
                if ($processed >= $limitNew) break; // Throttle upstream calls

                // Search iNat by Exact Name
                // We assume strict match to avoid showing wrong species
                
                // Check cache first to avoid hitting iNat for "No Result" repeatedly
                $noPhotoCacheKey = 'inat_no_photo_' . md5($name);
                if (Cache::has($noPhotoCacheKey)) {
                    continue; // Skip, we already know it has no photo
                }

                try {
                    // Search iNaturalist for this specific name
                    $iNatResult = $this->iNaturalistService->searchTaxon($name, [
                        'per_page' => 1,
                        'rank' => 'species'
                    ]);

                    if ($iNatResult['success'] && !empty($iNatResult['data'])) {
                        $bestMatch = $iNatResult['data'][0];

                        // Verify Name Match using normalized key
                        $bestSciName = $bestMatch['scientific_name'] ?? null;
                        if ($bestSciName && stripos($bestSciName, $name) !== false) {

                            // CHECK PHOTO REQUIREMENT
                            $hasPhoto = !empty($bestMatch['default_photo']) && !empty($bestMatch['default_photo']['url']);

                            if ($hasPhoto) {
                                // Save to DB
                                $savedTaxon = $this->createOrUpdateTaxonFromApiData($bestMatch);
                                if ($savedTaxon) {
                                    if (empty($filters['enrich']) || $filters['enrich'] === 'false') {
                                         // Light payload
                                         $finalTaxa[] = [
                                             'id' => $savedTaxon->id,
                                             'scientific_name' => $savedTaxon->scientific_name,
                                             'common_name' => $savedTaxon->common_name,
                                             'default_photo' => ['url' => $bestMatch['default_photo']['url'] ?? null],
                                         ];
                                    } else {
                                        $finalTaxa[] = $savedTaxon->enriched_data;
                                    }
                                }
                            } else {
                                // Cache "No Photo" state for 7 days
                                Cache::put($noPhotoCacheKey, true, 86400 * 7);
                            }
                        }
                    } else {
                        // Cache "Not Found" state
                        Cache::put($noPhotoCacheKey, true, 86400 * 7);
                    }
                } catch (\Exception $e) {
                    Log::error('Error enriching ' . $name, ['error' => $e->getMessage()]);
                }
                $processed++;
            }

            // Pagination from GBIF result (approximate)
            $pagination = $gbifResult['pagination'] ?? [];
            $pagination['current_page'] = $filters['page'] ?? 1;

            return [
                'success' => true,
                'data' => $finalTaxa,
                'pagination' => $pagination,
                'source' => 'hybrid_gbif_inat'
            ];

        } catch (\Exception $e) {
            Log::error('Hybrid Search Failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return [
                'success' => false,
                'error' => ['message' => 'Error getting species list: ' . $e->getMessage()],
                'source' => 'hybrid_fail'
            ];
        }
    }

    /**
     * Obtiene especies aleatorias de la base de datos local
     */
    private function getRandomSpeciesFromDatabase(array $filters): array
    {
        try {
            $perPage = $filters['per_page'] ?? 24;
            $page = $filters['page'] ?? 1;

            // Construir query base
            $query = Taxa::query()
                ->where('rank', 'species')
                ->whereNotNull('default_photo_id');

            // Aplicar filtros
            if (isset($filters['native']) && $filters['native']) {
                $query->where('establishment_status_colombia', 'native');
            }
            if (isset($filters['endemic']) && $filters['endemic']) {
                $query->where('establishment_status_colombia', 'endemic');
            }
            if (isset($filters['threatened']) && $filters['threatened']) {
                $query->whereNotNull('conservation_status')
                      ->whereIn('conservation_status', ['CR', 'EN', 'VU']);
            }
            if (isset($filters['rank']) && $filters['rank'] !== 'Todas') {
                $query->where('class', $filters['rank']);
            }

            // Ordenamiento ALEATORIO usando RAND() de MySQL
            $query->inRandomOrder();

            // Paginar
            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            // Formatear resultados
            $formattedTaxa = $paginator->items();
            $enrichedData = collect($formattedTaxa)->map->enriched_data->toArray();

            Log::info('Random mode: using database', [
                'total' => $paginator->total(),
                'per_page' => $perPage,
                'current_page' => $page
            ]);

            return [
                'success' => true,
                'data' => $enrichedData,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $paginator->lastPage(),
                ],
                'cached' => false,
                'source' => 'database',
            ];

        } catch (\Exception $e) {
            Log::error('Error en modo aleatorio: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => ['message' => 'Error al obtener especies aleatorias: ' . $e->getMessage()],
                'source' => 'database',
            ];
        }
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
