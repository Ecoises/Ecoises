<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EnrichSpeciesEcologyJob;
use App\Services\TaxonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TaxonController extends Controller
{
    /**
     * @var TaxonService
     */
    protected $taxonService;
    
    /**
     * @param TaxonService $taxonService
     */
    public function __construct(TaxonService $taxonService)
    {
        $this->taxonService = $taxonService;
    }
    
    /**
     * Listar todos los taxones con paginación
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'rank' => 'sometimes|string|in:species,genus,family,order,class,phylum,kingdom',
            'enrich' => 'sometimes',  // ← Agregado para consistencia
        ]);
        
        $filters = $request->only(['per_page', 'page', 'rank', 'enrich']);
        $result = $this->taxonService->getAllTaxa($filters);
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al obtener los taxones',
                'code' => $result['error']['code'] ?? 500,
                'data' => null,
            ], $result['error']['code'] ?? 500);
        }
        
        // ← Fix: Enriquecer si se pide (asumiendo getAllTaxa retorna models)
        $shouldEnrich = $request->boolean('enrich', true);
        $data = $shouldEnrich ? collect($result['data'])->map->enriched_data->toArray() : $result['data'];
        
        return response()->json([
            'success' => true,
            'message' => 'Taxones obtenidos correctamente',
            'data' => $data,
            'meta' => [
                'source' => $result['source'] ?? 'local',
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? $result['meta']['pagination'] ?? null,
            ],
        ]);
    }
    
    /**
     * Buscar taxones por nombre científico o común
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'rank' => 'sometimes|string|in:species,genus,family,order,class,phylum,kingdom',
            'enrich' => 'sometimes',
        ]);
        
        $query = $request->input('q');
        $filters = $request->only(['per_page', 'page', 'rank', 'enrich']);
        
        // Si el query es 'all', buscamos sin filtro de búsqueda
        if ($query === 'all') {
            $result = $this->taxonService->getAllTaxa($filters);
        } else {
            $result = $this->taxonService->searchTaxa($query, $filters);
        }
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al buscar taxones',
                'code' => $result['error']['code'] ?? 500,
                'data' => null,
            ], $result['error']['code'] ?? 500);
        }

        // ← Fix: Usa $data (redundante removida, pero consistente con enriched del service)
        $data = $result['data'];  // Ya viene enriquecido del service
        
        return response()->json([
            'success' => true,
            'message' => 'Búsqueda completada correctamente',
            'data' => $data,
            'meta' => [
                'source' => $result['source'] ?? 'unknown',
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? $result['meta']['pagination'] ?? null,
            ],
        ]);
    }

    /**
     * Obtener un taxón por su ID
     *
     * @param int $id
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id, Request $request)
    {
        $request->validate([
            'refresh' => 'sometimes',
            'enrich' => 'sometimes',
        ]);
        
        $forceRefresh = $request->boolean('refresh', false);
        $shouldEnrich = $request->boolean('enrich', true);
        $result = $this->taxonService->getTaxonById($id, $forceRefresh);
        
        if (!$result['success']) {
            $status = (int) ($result['error']['code'] ?? 502);
            if ($status < 400 || $status > 599) {
                $status = 502;
            }

            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al obtener el taxón',
                'code' => $status,
                'data' => null,
            ], $status);
        }

        // ← Fix: Usa $data y maneja si no es modelo
        $taxon = $result['data'];
        $taxon->load('apiReferences');

        $eolReference = $taxon->apiReferences->firstWhere('api_source', 'eol');
        $ecologyIsStale = !$eolReference
            || !$eolReference->last_verified_at
            || $eolReference->last_verified_at->lt(now()->subMonths(6));

        // Enriquecer sincrónicamente para que el usuario reciba la información de inmediato
        if ($ecologyIsStale) {
            try {
                app(\App\Jobs\EnrichSpeciesEcologyJob::class, ['taxonId' => $taxon->id])
                    ->handle(app(\App\Services\Api\EolService::class));
                $taxon->load('apiReferences');
            } catch (\Throwable $e) {
                Log::warning("Error enriqueciendo ecología para taxón {$taxon->id}: " . $e->getMessage());
            }
        }

        $data = $shouldEnrich ? $taxon->enriched_data : $taxon;
        
        return response()->json([
            'success' => true,
            'message' => 'Taxón obtenido correctamente',
            'data' => $data,
            'meta' => [
                'source' => $result['source'] ?? 'unknown',
                'cached' => $result['cached'] ?? false,
            ],
        ]);
    }
    
    /**
     * Obtener las observaciones de un taxón
     *
     * @param int $taxonId
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function observations($taxonId, Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'quality_grade' => 'sometimes|string|in:research,needs_id,casual',
            'order_by' => 'sometimes|string|in:created_at,observed_on,votes',
            'order' => 'sometimes|string|in:asc,desc',
        ]);
        
        $params = $request->only(['per_page', 'page', 'quality_grade', 'order_by', 'order']);
        $result = $this->taxonService->getTaxonObservations($taxonId, $params);
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al obtener las observaciones del taxón',
                'code' => $result['error']['code'] ?? 500,
                'data' => null,
            ], $result['error']['code'] ?? 500);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Observaciones obtenidas correctamente',
            'data' => $result['data'],
            'meta' => [
                'source' => $result['source'] ?? 'unknown',
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? null,
            ],
        ]);
    }
    
    /**
     * Lista especies de Colombia para exploración
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * Lista especies del catálogo local enriquecidas con iNaturalist
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function explore(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'q' => 'sometimes|string|max:255',
            'rank' => 'sometimes|string',
            'native' => 'sometimes',
            'endemic' => 'sometimes',
            'threatened' => 'sometimes',
            'iconic_taxa' => 'sometimes|string',
            'lat' => 'sometimes|numeric',
            'lng' => 'sometimes|numeric',
            'radius' => 'sometimes|numeric',
            'order_by' => 'sometimes|string', // observed_on, random
        ]);

        // Obtener parámetros de la solicitud
        $params = $request->only([
            'per_page', 'page', 'q', 'rank',
            'native', 'endemic', 'threatened', 'iconic_taxa',
            'lat', 'lng', 'radius', 'order_by'
        ]);

        $result = $this->taxonService->getSpeciesNearLocation($params);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al obtener las especies',
                'code' => $result['error']['code'] ?? 500,
                'data' => null,
            ], $result['error']['code'] ?? 500);
        }

        $data = $result['data'];

        return response()->json([
            'success' => true,
            'message' => 'Especies locales obtenidas correctamente',
            'data' => $data,
            'meta' => [
                'source' => $result['source'] ?? 'local',
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? null,
            ],
        ]);
    }

    /**
     * Lista especies cercanas a la ubicación del usuario
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listSpeciesByPlace(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'order_by' => 'sometimes|string|in:observations_count,created_at,observed_on',
            'order' => 'sometimes|string|in:asc,desc',
            'rank' => 'sometimes|string|in:species,genus,family',
            'enrich' => 'sometimes',  // ← Agregado para consistencia
        ]);

        // Obtener parámetros de la solicitud
        $params = $request->only(['per_page', 'page', 'order_by', 'order', 'rank', 'enrich']);

        // Obtener las especies cercanas a la ubicación
        $result = $this->taxonService->getPlaceObservations($params);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al obtener las especies cercanas',
                'code' => $result['error']['code'] ?? 500,
                'data' => null,
            ], $result['error']['code'] ?? 500);
        }

        // ← Fix: Enriquecer si se pide
        $shouldEnrich = $request->boolean('enrich', true);
        $data = $shouldEnrich ? collect($result['data'])->map->enriched_data->toArray() : $result['data'];

        // Construir la respuesta con metadatos de ubicación si están disponibles
        $response = [
            'success' => true,
            'message' => 'Especies cercanas obtenidas correctamente',
            'data' => $data,
            'meta' => [
                'source' => $result['source'] ?? 'unknown',
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? null,
            ]
        ];

        // Agregar información de ubicación si está disponible
        if (isset($result['location'])) {
            $response['meta']['location'] = $result['location'];
        }

        return response()->json($response);
    }
    
    /**
     * Sincronizar las observaciones de un taxón desde la API
     *
     * @param int $taxonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncObservations($taxonId)
    {
        try {
            // Primero obtenemos el taxón para asegurarnos de que existe
            $taxonResult = $this->taxonService->getTaxonById($taxonId, true);  // ← Ahora funciona
            
            if (!$taxonResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $taxonResult['error']['message'] ?? 'Error al obtener el taxón',
                    'code' => $taxonResult['error']['code'] ?? 404,
                    'data' => null,
                ], $taxonResult['error']['code'] ?? 404);
            }
            
            // Obtenemos las observaciones de la API
            $observationsResult = $this->taxonService->getTaxonObservations($taxonId, [
                'per_page' => 30, // Límite razonable para la sincronización inicial
                'quality_grade' => 'research', // Solo observaciones de calidad investigativa
            ]);
            
            if (!$observationsResult['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $observationsResult['error']['message'] ?? 'Error al obtener las observaciones del taxón',
                    'code' => $observationsResult['error']['code'] ?? 500,
                    'data' => null,
                ], $observationsResult['error']['code'] ?? 500);
            }
            
            // Procesamos y almacenamos las observaciones
            $processResult = $this->taxonService->processAndStoreObservations(
                $observationsResult['data'],
                $taxonId
            );
            
            $message = sprintf(
                'Se sincronizaron %d de %d observaciones para el taxón %s',
                $processResult['stored_count'],
                $processResult['total_processed'],
                $taxonResult['data']->scientific_name ?? 'ID: ' . $taxonId
            );
            
            if ($processResult['error_count'] > 0) {
                Log::warning('Algunas observaciones no se pudieron procesar', [
                    'taxon_id' => $taxonId,
                    'errors' => $processResult['errors'],
                ]);
                
                $message .= sprintf(' (%d errores)', $processResult['error_count']);
            }
            
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'taxon' => $taxonResult['data']->enriched_data,  // ← Fix: Enriquecido
                    'observations' => $processResult,
                ],
                'meta' => [
                    'source' => $observationsResult['source'] ?? 'unknown',
                    'cached' => $observationsResult['cached'] ?? false,
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en syncObservations: ' . $e->getMessage(), [
                'taxon_id' => $taxonId,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor al sincronizar observaciones',
                'code' => 500,
                'data' => null,
            ], 500);
        }
    }

    /**
     * Obtener estadísticas de un taxón
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function stats($id)
    {
        try {
            $result = $this->taxonService->getTaxonById($id);  // ← Ahora funciona
            
            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error']['message'] ?? 'Error al obtener el taxón',
                    'code' => $result['error']['code'] ?? 404,
                    'data' => null,
                ], $result['error']['code'] ?? 404);
            }
            
            $taxon = $result['data'];
            $enriched = $taxon->enriched_data;  // ← Fix: Usa enriched para extras como rank, extinct
            
            // Obtener observaciones básicas para estadísticas
            $observationsResult = $this->taxonService->getTaxonObservations($id, [
                'per_page' => 1, // Solo necesitamos el total
                'quality_grade' => 'research',
            ]);
            
            $stats = [
                'taxon_id' => $id,
                'scientific_name' => $enriched['scientific_name'] ?? $enriched['name'] ?? null,
                'common_name' => $enriched['common_name'] ?? null,
                'rank' => $enriched['rank'] ?? null,  // ← Del JSON
                'observations_count' => $enriched['observation_count'] ?? 0,
                'api_observations_count' => $observationsResult['success'] 
                    ? ($observationsResult['pagination']['total'] ?? 0) 
                    : 0,
                'extinction_status' => ($enriched['extinct'] ?? false) ? 'extinct' : 'not_extinct',  // ← Del JSON
                'conservation_status' => $enriched['conservation_status'] ?? 'unknown',
                'has_photos' => !empty($enriched['default_photo']) || !empty($enriched['taxon_photos']),
                'wikipedia_available' => !empty($enriched['wikipedia_url']),
                'last_updated' => $enriched['updated_at'] ?? null,
            ];
            
            return response()->json([
                'success' => true,
                'message' => 'Estadísticas obtenidas correctamente',
                'data' => $stats,
                'meta' => [
                    'source' => $result['source'] ?? 'local',
                    'cached' => $result['cached'] ?? false,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en stats: ' . $e->getMessage(), [
                'taxon_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error interno del servidor al obtener estadísticas',
                'code' => 500,
                'data' => null,
            ], 500);
        }
    }
    /**
     * Obtener especies relacionadas
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function related($id)
    {
        $result = $this->taxonService->getRelatedSpecies($id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Error al obtener especies relacionadas',
                'data' => [],
            ], 200); // Retornamos 200 con array vacío para no romper el frontend
        }

        // Enriquecer datos (usar enriched_data para fotos, etc.)
        $data = collect($result['data'])->map(function($taxon) {
            // Si es un modelo, enriquecer. Si es array de API, normalizar la galería.
            if ($taxon instanceof \App\Models\Taxa) {
                return $taxon->enriched_data;
            }
            // Si viene directo de la API (array), ya debería estar medio listo,
            // pero nos aseguramos de que tenga 'gallery' si INaturalistService lo puso.
            return $taxon; 
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
