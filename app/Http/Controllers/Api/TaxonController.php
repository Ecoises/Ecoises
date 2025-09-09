<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * Buscar taxones por nombre científico o común
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request)
    {
        $request->validate([
            'q' => 'required|string|min:2|max:255',
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'rank' => 'sometimes|string|in:species,genus,family,order,class,phylum,kingdom',
        ]);
        
        $query = $request->input('q');
        $filters = $request->only(['per_page', 'page', 'rank']);
        
        $result = $this->taxonService->searchTaxa($query, $filters);
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al buscar taxones',
                'data' => null,
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Búsqueda completada correctamente',
            'data' => $result['data'],
            'meta' => [
                'source' => $result['source'],
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? null,
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
            'refresh' => 'sometimes|boolean',
        ]);
        
        $forceRefresh = $request->boolean('refresh', false);
        $result = $this->taxonService->getTaxonById($id, $forceRefresh);
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al obtener el taxón',
                'data' => null,
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Taxón obtenido correctamente',
            'data' => $result['data'],
            'meta' => [
                'source' => $result['source'],
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
    /**
     * Obtiene observaciones de un taxón específico
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
                'data' => null,
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Observaciones obtenidas correctamente',
            'data' => $result['data'],
            'meta' => [
                'source' => $result['source'],
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? null,
            ],
        ]);
    }
    
    /**
     * Lista especies observadas en un lugar específico
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function listSpeciesByPlace(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'order_by' => 'sometimes|string|in:created_at,observed_on,species_guess',
            'order' => 'sometimes|string|in:asc,desc',
        ]);
        
        $params = $request->only(['per_page', 'page', 'order_by', 'order']);
        
        // Forzamos el place_id=12731 como solicitado
        $params['place_id'] = '12731';
        
        // Obtenemos las observaciones del lugar
        $result = $this->taxonService->getPlaceObservations($params);
        
        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? 'Error al obtener las especies del lugar',
                'data' => null,
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Especies obtenidas correctamente',
            'data' => $result['data'],
            'meta' => [
                'source' => $result['source'],
                'cached' => $result['cached'] ?? false,
                'pagination' => $result['pagination'] ?? null,
            ],
        ]);
    }
    
    /**
     * Sincronizar las observaciones de un taxón desde la API
     *
     * @param int $taxonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncObservations($taxonId)
    {
        // Primero obtenemos el taxón para asegurarnos de que existe
        $taxonResult = $this->taxonService->getTaxonById($taxonId, true);
        
        if (!$taxonResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $taxonResult['error']['message'] ?? 'Error al obtener el taxón',
                'data' => null,
            ], 404);
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
                'data' => null,
            ], 500);
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
            $taxonResult['data']->scientific_name
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
                'taxon' => $taxonResult['data'],
                'observations' => $processResult,
            ],
            'meta' => [
                'source' => $observationsResult['source'],
                'cached' => $observationsResult['cached'] ?? false,
            ],
        ]);
    }
}
