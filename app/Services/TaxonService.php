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
     * Busca taxones por nombre científico o común
     *
     * @param string $query
     * @param array $filters
     * @return array
     */
    public function searchTaxa(string $query, array $filters = []): array
    {
        // Primero intentamos buscar en la base de datos local
        $localResults = $this->searchLocalTaxa($query, $filters);
        
        // Si hay resultados locales, los devolvemos
        if (!empty($localResults['data'])) {
            $localResults['source'] = 'local';
            return $localResults;
        }
        
        // Si no hay resultados locales, buscamos en la API de iNaturalist
        $apiResults = $this->iNaturalistService->searchTaxon($query, $filters);
        
        if (!$apiResults['success']) {
            return [
                'success' => false,
                'error' => $apiResults['error'] ?? ['message' => 'Error al buscar taxones en la API'],
                'source' => 'api',
            ];
        }
        
        // Procesamos y guardamos los resultados de la API
        $savedTaxa = [];
        
        foreach ($apiResults['data'] as $taxonData) {
            $savedTaxon = $this->createOrUpdateTaxonFromApiData($taxonData);
            if ($savedTaxon) {
                $savedTaxa[] = $savedTaxon;
            }
        }
        
        return [
            'success' => true,
            'data' => $savedTaxa,
            'pagination' => $apiResults['pagination'] ?? [],
            'cached' => $apiResults['cached'] ?? false,
            'source' => 'api',
        ];
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
     * Crea o actualiza un taxón a partir de los datos de la API
     *
     * @param array $taxonData
     * @return Taxa|null
     */
    protected function createOrUpdateTaxonFromApiData(array $taxonData): ?Taxa
    {
        if (empty($taxonData['id'])) {
            return null;
        }
        
        try {
            DB::beginTransaction();
            
            // Buscamos el taxón por su ID
            $taxon = Taxa::find($taxonData['id']);
            
            // Si no existe, lo creamos
            if (!$taxon) {
                $taxon = new Taxa();
                $taxon->id = $taxonData['id'];
            }
            
            // Actualizamos los campos del taxón
            $taxon->scientific_name = $taxonData['scientific_name'];
            $taxon->common_name = $taxonData['common_name'] ?? null;
            $taxon->rank = $taxonData['rank'] ?? null;
            $taxon->rank_level = $taxonData['rank_level'] ?? null;
            $taxon->extinct = $taxonData['extinct'] ?? false;
            $taxon->threatened = $taxonData['threatened'] ?? false;
            $taxon->wikipedia_url = $taxonData['wikipedia_url'] ?? null;
            $taxon->wikipedia_summary = $taxonData['wikipedia_summary'] ?? null;
            $taxon->observations_count = $taxonData['observations_count'] ?? 0;
            $taxon->iconic_taxon_name = $taxonData['iconic_taxon_name'] ?? null;
            
            // Guardamos el taxón
            $taxon->save();
            
            // Actualizamos o creamos la referencia a la API
            $this->updateOrCreateApiReference($taxon, $taxonData);
            
            DB::commit();
            
            return $taxon->load('apiReferences');
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al guardar el taxón: ' . $e->getMessage(), [
                'taxon_id' => $taxonData['id'] ?? null,
                'exception' => $e
            ]);
            return null;
        }
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
        $apiReference = TaxonApiReference::updateOrCreate(
            [
                'taxon_id' => $taxon->id,
                'api_source' => 'inaturalist',
            ],
            [
                'external_id' => $taxonData['id'],
                'api_url' => $taxonData['url'] ?? null,
                'data' => json_encode($taxonData),
                'last_synced_at' => now(),
            ]
        );
        
        // Si hay una foto por defecto, la guardamos en el caché unificado
        if (!empty($taxonData['default_photo'])) {
            UnifiedApiCache::updateOrCreate(
                [
                    'cache_key' => "taxon_photo_{$taxon->id}",
                    'api_source' => 'inaturalist',
                ],
                [
                    'data' => json_encode($taxonData['default_photo']),
                    'expires_at' => now()->addDays(30),
                ]
            );
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
