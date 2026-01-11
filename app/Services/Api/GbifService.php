<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Log;

class GbifService extends BaseApiService
{
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
            'facetLimit' => $filters['per_page'] ?? 20,
            'facetOffset' => ($filters['per_page'] ?? 20) * (($filters['page'] ?? 1) - 1),
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
    public function getSpeciesDetails($key) {
        $res = $this->getTaxonById((string)$key);
        return $res['success'] ? $this->makeRequest('get', "/species/{$key}", [], true)['data'] : null;
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
