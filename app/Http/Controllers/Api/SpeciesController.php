<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExternalApisService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use App\Models\Taxa;
use App\Models\Location;

class SpeciesController extends Controller
{
    protected $externalApisService;

    public function __construct(ExternalApisService $externalApisService)
    {
        $this->externalApisService = $externalApisService;
    }

    /**
     * Buscar especies (observadas y no observadas)
     */
    public function search(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'q' => 'required|string|min:3|max:255',
            'limit' => 'integer|min:1|max:50',
            'include_non_observed' => 'boolean',
            'location_id' => 'nullable|integer|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de búsqueda inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        $query = $request->input('q');
        $limit = $request->input('limit', 10);
        $includeNonObserved = $request->input('include_non_observed', false);
        $locationId = $request->input('location_id', config('campus.location_id', 1));
        $location = Location::findOrFail($locationId);

        try {
            // Especies observadas
            $localResults = Taxa::where(function ($q) use ($query) {
                    $q->where('scientific_name', 'LIKE', "%{$query}%")
                      ->orWhere('common_name', 'LIKE', "%{$query}%");
                })
                ->join('observations', 'taxa.id', '=', 'observations.taxon_id')
                ->where('observations.location_id', $locationId)
                ->groupBy('taxa.id')
                ->limit($limit)
                ->get();

            $results = [
                'observed' => $localResults->map(function ($taxon) {
                    $points = in_array($taxon->conservation_status, ['CR', 'EN', 'VU']) ? 60 : 10;
                    return [
                        'id' => $taxon->id,
                        'scientific_name' => $taxon->scientific_name,
                        'common_name' => $taxon->common_name,
                        'conservation_status' => $taxon->conservation_status,
                        'is_endemic' => $taxon->is_endemic,
                        'observation_count' => $taxon->observation_count,
                        'source' => 'local',
                        'points' => $points
                    ];
                })->toArray()
            ];

            // Especies no observadas
            if ($includeNonObserved) {
                $nearbySpecies = $this->externalApisService->findNearbySpecies(
                    $location->latitude,
                    $location->longitude,
                    50 // Radio en km para especies regionales
                );
                $nonObserved = array_filter($nearbySpecies, fn ($species) => !$localResults->contains('id', $species['taxon_id']));
                $results['non_observed'] = array_map(function ($species) {
                    return array_merge($species, [
                        'source' => 'regional',
                        'points' => 100 // Puntos por primera observación
                    ]);
                }, $nonObserved);
            }

            return response()->json([
                'success' => true,
                'data' => $results,
                'query' => $query
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error en la búsqueda: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar detalles de una especie
     */
    public function show(int $id): JsonResponse
    {
        try {
            $taxon = Taxa::findOrFail($id);
            $apiData = $this->externalApisService->getTaxonDetails($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $taxon->id,
                    'scientific_name' => $taxon->scientific_name,
                    'common_name' => $taxon->common_name,
                    'kingdom' => $taxon->kingdom,
                    'phylum' => $taxon->phylum,
                    'class' => $taxon->class,
                    'order_name' => $taxon->order_name,
                    'family' => $taxon->family,
                    'genus' => $taxon->genus,
                    'species' => $taxon->species,
                    'conservation_status' => $taxon->conservation_status,
                    'is_native' => $taxon->is_native,
                    'is_endemic' => $taxon->is_endemic,
                    'observation_count' => $taxon->observation_count,
                    'api_data' => $apiData
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo detalles de la especie: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sugerir identificación para una observación
     */
    public function suggestIdentification(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'photo' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'location_id' => 'nullable|integer|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $locationId = $request->input('location_id', config('app.campus.location_id'));
            $location = Location::findOrFail($locationId);

            $suggestions = $this->externalApisService->suggestSpecies(
                $request->input('latitude'),
                $request->input('longitude'),
                $request->file('photo')
            );

            return response()->json([
                'success' => true,
                'suggestions' => $suggestions
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo sugerencias: ' . $e->getMessage()
            ], 500);
        }
    }
}