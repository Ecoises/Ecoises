<?php

namespace App\Services\Api;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use App\Models\UnifiedApiCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GbifService extends BaseApiService
{
    private const NEARBY_FACET_LIMIT = 2000;

    /**
     * @var string
     */
    protected $apiName = 'gbif';
    
    /**
     * @var int
     */
    protected $searchCacheTtl = 1440; // 24 horas

    /**
     * Inicialización
     */
    protected function initialize()
    {
        parent::initialize();
        // GBIF API V1 Base URL
        $this->config['base_url'] = 'https://api.gbif.org/v1';
        $this->config['timeout'] = 30;
    }

    /**
     * Obtiene lista de especies basada en ubicación y filtros
     *
     * @param array $filters
     * @return array
     */
    public function getSpeciesList(array $filters = []): array
    {
        // 1. Configurar parámetros base para buscar ocurrencias
        // Usamos /occurrence/search con facet=speciesKey para obtener especies únicas
        $params = [
            'country' => 'CO', // Colombia
            'limit' => 0,      // No queremos registros individuales, solo el facet
            'facet' => 'speciesKey',
            'facetLimit' => 300,  // Máximo de especies por request (GBIF permite esto)
            'facetOffset' => 300 * (($filters['page'] ?? 1) - 1),  // Paginación de 300
            'hasCoordinate' => 'true',
            'hasGeospatialIssue' => 'false',
        ];

        // 2. Aplicar filtros geográficos
        // GBIF usa stateProvince para Departamentos y municipality/county para Municipios (depende de la data)
        // Nota: Filtrar por texto exacto puede ser frágil. Idealmente usar GADM GIDs si se tienen.
        // Por ahora intentaremos con búsqueda de texto en los campos indexados.
        
        if (!empty($filters['stateProvince'])) {
            $params['stateProvince'] = $filters['stateProvince'];
        }
        
        if (!empty($filters['municipality'])) {
            // GBIF no siempre tiene "municipality" estandarizado, a veces es "county"
            // Para búsqueda general, a veces conviene 'q' o campos específicos si se conocen
            $params['q'] = $filters['municipality']; 
        }

        // 3. Aplicar filtros taxonómicos (Clase, Reino, etc.)
        if (!empty($filters['class'])) {
            $params['class'] = $filters['class']; 
        }
        
        // 4. Búsqueda por nombre científico (si aplica)
        if (!empty($filters['q'])) {
            // Si el usuario busca un nombre, buscamos ocurrencias de ese nombre en la zona
            $params['q'] = $filters['q'];
        }

        Log::info('🌍 Consultando GBIF', ['params' => $params]);

        // Realizar la petición
        $response = $this->makeRequest('get', '/occurrence/search', $params, true);

        if (!$response['success']) {
            return $response;
        }

        // Procesar las facetas para obtener los IDs de especies
        $speciesBuckets = $response['data']['facets'][0]['counts'] ?? [];
        $speciesIds = array_column($speciesBuckets, 'name'); // 'name' en el bucket es el speciesKey

        if (empty($speciesIds)) {
             return [
                'success' => true,
                'data' => [],
                'pagination' => ['total' => 0],
                'source' => 'gbif'
            ];
        }

        // 5. Hydrate: Obtener nombres científicos completos para estos IDs
        // GBIF permite obtener detalles de especies por ID (/species/{id})
        // O podemos confiar en que la faceta solo da el ID y necesitamos el nombre.
        // Para optimizar, podríamos intentar resolver los nombres.
        // Pero para el MVP, necesitamos retornar una estructura que TaxonService pueda usar
        // para buscar en iNaturalist.
        
        // Necesitamos el nombre científico para buscar en iNaturalist
        // Pasamos $filters para poder calcular paginación sin variables indefinidas
        return $this->resolveSpeciesNames($speciesIds, $filters);
    }

    /**
     * Resuelve IDs de especies de GBIF a sus nombres científicos
     * 
     * @param array $speciesKeys
     * @return array
     */
    protected function resolveSpeciesNames(array $speciesKeys, array $filters = []): array
    {
        // No hay endpoint batch oficial simple en GBIF para /species
        // Pero podemos hacer un truco con /species/search?speciesKey=...
        // Ojo: URL length limits. Si son 20 IDs está bien.
        
        // OPCIÓN B: Iterar (rápido si es cacheado, pero N+1).
        // OPCIÓN MEJOR: La respuesta de /occurrence/search A VECES incluye info taxonómica si NO usas facets y limit > 0
        // Pero queremos UNICOS.
        // Probemos buscando los detalles de estas especies en paralelo o batch si es posible.
        
        // Por ahora, para el prototipo, haremos llamadas individuales cacheadas o un multi-handle si BaseApiService lo soportara.
        // Como BaseApiService es simple, haremos un loop. PERO GBIF es muy rápido y cacheable.
        
        $speciesList = [];
        
        foreach ($speciesKeys as $key) {
            $details = $this->getSpeciesDetails($key);
            if ($details && isset($details['scientificName'])) {
                // Limpiar nombre científico (quitar autoría si se desea match con iNat)
                // iNat suele entender con autoría, pero mejor nombre simple.
                $canonicalName = $details['canonicalName'] ?? $details['scientificName'];
                
                $speciesList[] = [
                    'source_id' => $key,
                    'scientific_name' => $canonicalName,
                    'rank' => $details['rank'] ?? 'SPECIES',
                    'kingdom' => $details['kingdom'] ?? null,
                    'family' => $details['family'] ?? null,
                ];
            }
        }

        // Calcular paginación basada en la cantidad devuelta por esta página
        $limit = (int)($filters['per_page'] ?? 20);
        $page = (int)($filters['page'] ?? 1);
        $currentCount = count($speciesKeys);
        // No tenemos el total real desde GBIF facets fácilmente; inferimos si podría haber más
        $hasMore = $currentCount >= $limit;

        return [
            'success' => true,
            'data' => $speciesList,
            'source' => 'gbif',
            'pagination' => [
                'page' => $page,
                'per_page' => $limit,
                'has_more' => $hasMore,
                'total' => ($hasMore ? ($page * $limit + 1) : $currentCount),
                'last_page' => ($hasMore ? ($page + 1) : $page),
            ]
        ];
    }

    /**
     * Obtiene detalles de una especie por Key
     */
    /**
     * Obtiene detalles de una especie por Key
     * Implementa getTaxonById de ApiServiceInterface
     */
    public function getTaxonById(string $id): array
    {
        $response = $this->makeRequest('get', "/species/{$id}", [], true);
        
        if (!$response['success']) {
            return $response;
        }

        // Normalizar estructura mínima para cumplir contrato (aunque no se use intensivamente)
        $data = $response['data'];
        $normalized = [
            'id' => $data['key'] ?? $id,
            'scientific_name' => $data['scientificName'] ?? null,
            'rank' => $data['rank'] ?? null,
            // GBIF no da fotos directas en /species/{id}, requieren media api
            'default_photo' => null 
        ];

        return [
            'success' => true,
            'data' => $normalized,
            'api' => $this->apiName
        ];
    }
    
    // Alias interno si se usa en otros lados, o refactorizar llamadas internas a getTaxonById
    public function getSpeciesDetails($key)
    {
        $response = $this->makeRequest('get', "/species/{$key}", [], true);

        return $response['success'] ? $response['data'] : null;
    }

    /**
     * Hydrates a page of GBIF species concurrently while preserving the
     * application's persistent API cache.
     *
     * @return array<string, array>
     */
    public function getSpeciesDetailsBatch(array $speciesKeys): array
    {
        $details = [];
        $missing = [];
        $keys = array_values(array_unique(array_map('strval', $speciesKeys)));
        $cacheKeysBySpecies = [];

        foreach ($keys as $key) {
            $cacheKeysBySpecies[$key] = $this->generateCacheKey('get', "/species/{$key}");
        }

        $cachedRows = UnifiedApiCache::query()
            ->whereIn('cache_key', array_values($cacheKeysBySpecies))
            ->where('expires_at', '>', now())
            ->get()
            ->keyBy('cache_key');
        $hitCacheKeys = [];

        foreach ($cacheKeysBySpecies as $key => $cacheKey) {
            $cached = $cachedRows->get($cacheKey);
            if ($cached) {
                $details[$key] = $cached->response_data;
                $hitCacheKeys[] = $cacheKey;
            } else {
                $missing[] = $key;
            }
        }

        if ($hitCacheKeys !== []) {
            UnifiedApiCache::query()
                ->whereIn('cache_key', $hitCacheKeys)
                ->update([
                    'hit_count' => DB::raw('hit_count + 1'),
                    'last_accessed_at' => now(),
                ]);
        }

        if ($missing === []) {
            return $details;
        }

        $baseUrl = rtrim($this->config['base_url'], '/');
        $timeout = (int) ($this->config['timeout'] ?? 30);

        $responses = Http::pool(function (Pool $pool) use ($missing, $baseUrl, $timeout) {
            return array_map(
                fn (string $key) => $pool
                    ->as($key)
                    ->withHeaders($this->getDefaultHeaders())
                    ->timeout($timeout)
                    ->get("{$baseUrl}/species/{$key}"),
                $missing
            );
        });

        foreach ($missing as $key) {
            $response = $responses[$key] ?? null;
            if (!$response instanceof Response || !$response->successful()) {
                Log::warning('No se pudo hidratar un taxón de GBIF', ['species_key' => $key]);
                continue;
            }

            $data = $response->json();
            if (!is_array($data)) {
                continue;
            }

            $endpoint = "/species/{$key}";
            $cacheKey = $this->generateCacheKey('get', $endpoint);
            $this->saveToCache($cacheKey, $endpoint, $data);
            $details[$key] = $data;
        }

        return $details;
    }

    /**
     * Obtiene en paralelo los nombres vernáculos respaldados por fuentes colombianas.
     *
     * @return array<string, string>
     */
    public function getColombianVernacularNamesBatch(array $speciesKeys): array
    {
        $names = [];
        $missing = [];
        $params = ['limit' => 100];
        $keys = array_values(array_unique(array_map('strval', $speciesKeys)));
        $cacheKeysBySpecies = [];

        foreach ($keys as $key) {
            $endpoint = "/species/{$key}/vernacularNames";
            $cacheKeysBySpecies[$key] = $this->generateCacheKey('get', $endpoint, $params);
        }

        $cachedRows = UnifiedApiCache::query()
            ->whereIn('cache_key', array_values($cacheKeysBySpecies))
            ->where('expires_at', '>', now())
            ->get()
            ->keyBy('cache_key');

        foreach ($cacheKeysBySpecies as $key => $cacheKey) {
            $cached = $cachedRows->get($cacheKey);
            if (!$cached) {
                $missing[] = $key;
                continue;
            }

            $name = $this->selectColombianVernacularName($cached->response_data['results'] ?? []);
            if ($name) {
                $names[$key] = $name;
            }
        }

        if ($missing === []) {
            return $names;
        }

        $baseUrl = rtrim($this->config['base_url'], '/');
        $timeout = (int) ($this->config['timeout'] ?? 30);
        $responses = Http::pool(function (Pool $pool) use ($missing, $baseUrl, $timeout, $params) {
            return array_map(
                fn (string $key) => $pool
                    ->as($key)
                    ->withHeaders($this->getDefaultHeaders())
                    ->connectTimeout(5)
                    ->timeout($timeout)
                    ->get("{$baseUrl}/species/{$key}/vernacularNames", $params),
                $missing
            );
        });

        foreach ($missing as $key) {
            $response = $responses[$key] ?? null;
            if (!$response instanceof Response || !$response->successful()) {
                continue;
            }

            $data = $response->json();
            if (!is_array($data)) {
                continue;
            }

            $endpoint = "/species/{$key}/vernacularNames";
            $cacheKey = $this->generateCacheKey('get', $endpoint, $params);
            $this->saveToCache($cacheKey, $endpoint, $data, null, $params);

            $name = $this->selectColombianVernacularName($data['results'] ?? []);
            if ($name) {
                $names[$key] = $name;
            }
        }

        return $names;
    }

    /**
     * Prioriza español + país CO, luego áreas CO y finalmente fuentes colombianas.
     */
    protected function selectColombianVernacularName(array $records): ?string
    {
        return collect($records)
            ->map(function (array $record): ?array {
                $name = trim((string) ($record['vernacularName'] ?? ''));
                $language = strtolower((string) ($record['language'] ?? ''));
                $country = strtoupper((string) ($record['country'] ?? ''));
                $area = strtoupper((string) ($record['area'] ?? ''));
                $source = strtolower((string) ($record['source'] ?? ''));
                $isSpanish = in_array($language, ['spa', 'es', 'spanish'], true);
                $hasNoLanguage = $language === '';
                $countryMatch = $country === 'CO';
                $areaMatch = str_contains($area, 'CO:');
                $sourceMatch = str_contains($source, 'colombia');

                if ($name === '' || (!$isSpanish && !$hasNoLanguage)) {
                    return null;
                }

                if (!$countryMatch && !$areaMatch && !$sourceMatch) {
                    return null;
                }

                $regionalScore = $countryMatch ? 0 : ($areaMatch ? 1 : 2);

                return [
                    'name' => $name,
                    'score' => ($isSpanish ? 0 : 10) + $regionalScore,
                ];
            })
            ->filter()
            ->sortBy('score')
            ->value('name');
    }

    /**
     * Busca taxones por nombre científico
     */
    public function searchTaxon(string $scientificName): array
    {
        $params = ['name' => $scientificName, 'verbose' => true];
        $response = $this->makeRequest('get', '/species/match', $params, true);
        
        if (!$response['success']) {
            return $response;
        }

        // /species/match retorna un solo objeto si hay match, o usageKey
        $data = $response['data'];
        // Ajustar formato a lista para consistencia con search genérico
        return [
            'success' => true,
            'data' => [$data], 
            'api' => $this->apiName
        ];
    }

    /**
     * Obtiene observaciones (ocurrencias) de un taxón
     */
    public function getTaxonObservations(string $taxonId, array $params = []): array
    {
        // Mapear parámetros a GBIF
        $gbifParams = [
            'taxonKey' => $taxonId,
            'limit' => $params['per_page'] ?? 20,
            'offset' => ($params['per_page'] ?? 20) * (($params['page'] ?? 1) - 1),
        ];
        
        return $this->makeRequest('get', '/occurrence/search', $gbifParams, true);
    }

    /**
     * Busca especies en una región geográfica (por coordenadas y radio)
     * 
     * Usa GBIF /occurrence/search con geometry (WKT) para hacer búsqueda de radio
     * y facet=speciesKey para obtener especies únicas sin paginación infinita.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @return array
     */
    public function searchOccurrencesByRegion(float $latitude, float $longitude, float $radiusKm = 50): array
    {
        $params = [
            'geoDistance' => sprintf('%.6f,%.6f,%skm', $latitude, $longitude, $radiusKm),
            'limit' => 300,
            'hasCoordinate' => 'true',
            'hasGeospatialIssue' => 'false',
            'occurrenceStatus' => 'PRESENT',
        ];

        Log::info('Busca ocurrencias GBIF por región', [
            'lat' => $latitude,
            'lon' => $longitude,
            'radius_km' => $radiusKm,
        ]);

        return $this->makeRequest('get', '/occurrence/search', $params, true);
    }

    /**
     * Returns the distinct species recorded inside a real circular radius.
     * GBIF facets avoid downloading and de-duplicating arbitrary occurrence pages.
     */
    public function searchSpeciesByRegion(
        float $latitude,
        float $longitude,
        float $radiusKm = 50,
        array $filters = []
    ): array {
        return $this->searchSpeciesCatalog([
            'geoDistance' => sprintf('%.6f,%.6f,%skm', $latitude, $longitude, $radiusKm),
        ], $filters, [
            'scope' => 'nearby',
            'lat' => $latitude,
            'lng' => $longitude,
            'radius_km' => $radiusKm,
        ]);
    }

    /**
     * Returns the distinct species documented in a country, independently of
     * which taxa have already been synchronized into the local database.
     */
    public function searchSpeciesByCountry(string $countryCode = 'CO', array $filters = []): array
    {
        return $this->searchSpeciesCatalog([
            'country' => strtoupper($countryCode),
        ], $filters, [
            'scope' => 'country',
            'country' => strtoupper($countryCode),
        ]);
    }

    private function searchSpeciesCatalog(array $scopeParams, array $filters, array $context): array
    {
        $facetLimit = min(
            self::NEARBY_FACET_LIMIT,
            max(100, (int) ($filters['catalog_limit'] ?? self::NEARBY_FACET_LIMIT))
        );

        $params = array_merge($scopeParams, [
            'limit' => 0,
            'facet' => 'speciesKey',
            'facetLimit' => $facetLimit,
            'facetOffset' => 0,
            'hasCoordinate' => 'true',
            'hasGeospatialIssue' => 'false',
            'occurrenceStatus' => 'PRESENT',
        ]);

        $requestedTaxon = $filters['taxon_key'] ?? null;
        $query = trim((string) ($filters['q'] ?? ''));

        if (!$requestedTaxon && $query !== '') {
            $taxonMatch = $this->searchTaxon($query);
            $match = $taxonMatch['data'][0] ?? null;
            $matchType = strtoupper((string) ($match['matchType'] ?? 'NONE'));
            $requestedTaxon = $matchType !== 'NONE'
                ? ($match['usageKey'] ?? $match['acceptedUsageKey'] ?? $match['key'] ?? null)
                : null;

            if (!$requestedTaxon) {
                return [
                    'success' => true,
                    'data' => [
                        'buckets' => [],
                        'occurrence_total' => 0,
                        'truncated' => false,
                        'facet_limit' => $facetLimit,
                    ],
                    'cached' => (bool) ($taxonMatch['cached'] ?? false),
                    'api' => $this->apiName,
                ];
            }
        }

        if (!$requestedTaxon && !empty($filters['iconic_taxa'])) {
            $taxonMatch = $this->searchTaxon((string) $filters['iconic_taxa']);
            $match = $taxonMatch['data'][0] ?? null;
            $requestedTaxon = $match['usageKey'] ?? $match['acceptedUsageKey'] ?? $match['key'] ?? null;
        }

        if ($requestedTaxon) {
            $params['taxonKey'] = $requestedTaxon;
        }

        if (!empty($filters['native'])) {
            $params['establishmentMeans'] = 'Native';
        }

        Log::info('Consultando catálogo de especies de GBIF', array_merge($context, [
            'facet_limit' => $facetLimit,
            'has_query' => $query !== '',
            'taxon_key' => $params['taxonKey'] ?? null,
        ]));

        $response = $this->makeRequest('get', '/occurrence/search', $params, true);
        if (!$response['success']) {
            return $response;
        }

        $facet = collect($response['data']['facets'] ?? [])
            ->first(function (array $item): bool {
                $field = strtoupper(str_replace('-', '_', (string) ($item['field'] ?? '')));
                return in_array($field, ['SPECIES_KEY', 'SPECIESKEY'], true);
            });

        $buckets = collect($facet['counts'] ?? [])
            ->filter(fn (array $bucket) => isset($bucket['name']))
            ->map(fn (array $bucket) => [
                'species_key' => (string) $bucket['name'],
                'occurrence_count' => (int) ($bucket['count'] ?? 0),
            ])
            ->values()
            ->all();

        return [
            'success' => true,
            'data' => [
                'buckets' => $buckets,
                'occurrence_total' => (int) ($response['data']['count'] ?? 0),
                'truncated' => count($buckets) >= $facetLimit,
                'facet_limit' => $facetLimit,
            ],
            'cached' => $response['cached'] ?? false,
            'api' => $this->apiName,
        ];
    }
    /**
     * Obtiene info de ubicación
     * GBIF no maneja locations como entidades simples consultables por ID numérico estándar
     * Retornamos vacío o simulado.
     */
    public function getLocationInfo(string $locationId): array
    {
        return [
            'success' => false, 
            'error' => 'Method not supported by GBIF service',
            'api' => $this->apiName
        ];
    }

    /**
     * Info de la API
     */
    public function getApiInfo(): array
    {
        return [
            'name' => 'GBIF API',
            'version' => 'v1',
            'details' => 'Global Biodiversity Information Facility'
        ];
    }
}
