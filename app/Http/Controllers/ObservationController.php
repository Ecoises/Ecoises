<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ObservationService;
use App\Services\AchievementService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use App\Models\Observation;
use App\Models\PointTransaction;
use App\Models\Location;
use App\Models\Taxa;
use App\Models\User;
use App\Models\ObservationPhoto;
use App\Models\Identification;

class ObservationController extends Controller
{
    protected $observationService;
    protected $achievementService;

    public function __construct(ObservationService $observationService, AchievementService $achievementService)
    {
        $this->observationService = $observationService;
        $this->achievementService = $achievementService;
    }

    /**
     * Listar observaciones públicas con filtros
     */
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
            'taxon_id' => 'integer|exists:taxa,id',
            'user_id' => 'integer|exists:users,id',
            'latitude' => 'numeric|between:-90,90',
            'longitude' => 'numeric|between:-180,180',
            'radius_km' => 'numeric|min:0.1|max:100',
            'date_from' => 'date',
            'date_to' => 'date|after_or_equal:date_from',
            'status' => Rule::in(['sin_identificar', 'sugerida', 'confirmada', 'controvertida']),
            'sort_by' => Rule::in(['created_at', 'observed_at', 'quality_score']),
            'sort_order' => Rule::in(['asc', 'desc']),
            'location_id' => 'nullable|integer|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $query = Observation::with(['taxon', 'photos', 'user:id,username'])
                ->where('is_public', true);

            // Obtener location_id con valor por defecto
            $locationId = $request->input('location_id', $this->getDefaultLocationId());
            if ($locationId) {
                $query->where('location_id', $locationId);
            }

            // Filtros adicionales
            if ($request->has('taxon_id')) {
                $query->where('taxon_id', $request->input('taxon_id'));
            }

            if ($request->has('user_id')) {
                $query->where('user_id', $request->input('user_id'));
            }

            if ($request->has('latitude') && $request->has('longitude') && $request->has('radius_km')) {
                $lat = $request->input('latitude');
                $lng = $request->input('longitude');
                $radius = $request->input('radius_km');
                $query->whereRaw("(6371 * acos(cos(radians(?)) 
                    * cos(radians(latitude)) 
                    * cos(radians(longitude) - radians(?)) 
                    + sin(radians(?)) 
                    * sin(radians(latitude)))) < ?", [$lat, $lng, $lat, $radius]);
            }

            if ($request->has('date_from')) {
                $query->whereDate('observed_at', '>=', $request->input('date_from'));
            }

            if ($request->has('date_to')) {
                $query->whereDate('observed_at', '<=', $request->input('date_to'));
            }

            if ($request->has('status')) {
                $query->where('identification_status', $request->input('status'));
            }

            $sortBy = $request->input('sort_by', 'observed_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->input('per_page', 20);
            $observations = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $observations->getCollection()->map(function ($obs) {
                    $points = PointTransaction::where('reference_type', 'observation')
                        ->where('reference_id', $obs->id)
                        ->value('points') ?? 0;
                    return (object) [
                        'id' => $obs->id,
                        'taxon_id' => $obs->taxon_id,
                        'scientific_name' => $obs->taxon?->scientific_name,
                        'common_name' => $obs->taxon?->common_name,
                        'observed_at' => $obs->observed_at,
                        'user' => $obs->user->username,
                        'photos' => $obs->photos,
                        'points_earned' => $points,
                        'is_first_observation' => PointTransaction::where('reference_type', 'observation')
                            ->where('reference_id', $obs->id)
                            ->where('description', 'Primera observación de especie')
                            ->exists()
                    ];
                })->values(),
                'meta' => [
                    'current_page' => $observations->currentPage(),
                    'last_page' => $observations->lastPage(),
                    'per_page' => $observations->perPage(),
                    'total' => $observations->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo observaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar observaciones públicas recientes
     */
    public function publicObservations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'integer|min:1|max:50',
            'location_id' => 'nullable|integer|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $perPage = $request->input('per_page', 20);
            $locationId = $request->input('location_id', $this->getDefaultLocationId());

            $query = Observation::with(['taxon', 'photos', 'user:id,username'])
                ->where('is_public', true);
                
            if ($locationId) {
                $query->where('location_id', $locationId);
            }
            
            $query->orderBy('observed_at', 'desc');

            $observations = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $observations->getCollection()->map(function ($obs) {
                    $points = PointTransaction::where('reference_type', 'observation')
                        ->where('reference_id', $obs->id)
                        ->value('points') ?? 0;
                    return (object) [
                        'id' => $obs->id,
                        'taxon_id' => $obs->taxon_id,
                        'scientific_name' => $obs->taxon?->scientific_name,
                        'common_name' => $obs->taxon?->common_name,
                        'observed_at' => $obs->observed_at,
                        'user' => $obs->user->username,
                        'photos' => $obs->photos,
                        'points_earned' => $points,
                        'is_first_observation' => PointTransaction::where('reference_type', 'observation')
                            ->where('reference_id', $obs->id)
                            ->where('description', 'Primera observación de especie')
                            ->exists()
                    ];
                })->values(),
                'meta' => [
                    'current_page' => $observations->currentPage(),
                    'last_page' => $observations->lastPage(),
                    'per_page' => $observations->perPage(),
                    'total' => $observations->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo observaciones públicas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear una nueva observación
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'taxon_id' => 'nullable|integer|exists:taxa,id',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'location_accuracy' => 'nullable|integer|min:1|max:10000',
            'location_name' => 'nullable|string|max:255',
            'location_description' => 'nullable|string|max:1000',
            'observed_at' => 'required|date|before_or_equal:now',
            'description' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:1000',
            'weather_conditions' => 'nullable|string|max:100',
            'temperature' => 'nullable|numeric|between:-50,60',
            'humidity' => 'nullable|integer|between:0,100',
            'is_public' => 'boolean',
            'location_id' => 'nullable|integer|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Obtener configuración de ubicación
            $locationId = $request->input('location_id', $this->getDefaultLocationId());
            
            if ($locationId) {
                $location = Location::findOrFail($locationId);

                // Verificar si está dentro del área
                $distance = 6371 * acos(cos(deg2rad($location->latitude)) 
                    * cos(deg2rad($request->input('latitude'))) 
                    * cos(deg2rad($request->input('longitude')) - deg2rad($location->longitude)) 
                    + sin(deg2rad($location->latitude)) 
                    * sin(deg2rad($request->input('latitude'))));

                if ($distance > $location->radius_km) {
                    return response()->json([
                        'success' => false,
                        'message' => 'La observación está fuera del área seleccionada'
                    ], 422);
                }
            }

            $data = $request->all();
            $data['user_id'] = Auth::id();
            $data['is_public'] = $request->boolean('is_public', true);
            $data['identification_status'] = $request->has('taxon_id') ? 'sugerida' : 'sin_identificar';
            $data['location_id'] = $locationId;

            // Crear observación
            $observation = $this->observationService->createObservation($data);

            // Calcular puntos
            $points = 0;
            $isFirstObservation = false;
            if ($request->has('taxon_id')) {
                $taxon = Taxa::findOrFail($request->input('taxon_id'));
                $isFirstObservation = !Observation::where('taxon_id', $taxon->id)
                    ->where('location_id', $locationId)
                    ->exists();

                $points = 10; // Base
                if ($isFirstObservation) {
                    $points += 100; // Primera observación
                }
                if (in_array($taxon->conservation_status, ['CR', 'EN', 'VU'])) {
                    $points += 50; // Especie amenazada
                }
                if ($taxon->is_endemic) {
                    $points += 30; // Especie endémica
                }

                // Registrar puntos
                PointTransaction::create([
                    'user_id' => Auth::id(),
                    'points' => $points,
                    'transaction_type' => 'observacion',
                    'reference_id' => $observation->id,
                    'reference_type' => 'observation',
                    'description' => $isFirstObservation ? 'Primera observación de especie' : 'Observación estándar'
                ]);

                // Actualizar usuario
                $user = Auth::user();
                $user->increment('total_score', $points);
                $user->increment('experience_points', $points);

                // Verificar logros
                $this->achievementService->checkAchievements($user, $observation);
            }

            // Sugerencias automáticas si no hay taxon_id
            if (!$request->has('taxon_id')) {
                // Aquí deberías implementar tu lógica de sugerencias automáticas
                // Por ahora lo dejo comentado para evitar errores
                /*
                $suggestions = app(\App\Http\Controllers\Api\SpeciesController::class)
                    ->suggestIdentification($request)
                    ->getData()->suggestions ?? [];
                if ($suggestions) {
                    Identification::create([
                        'observation_id' => $observation->id,
                        'user_id' => Auth::id(),
                        'taxon_id' => $suggestions[0]['taxon_id'],
                        'confidence' => 'baja',
                        'is_automatic' => true,
                        'ai_confidence' => $suggestions[0]['confidence'] ?? 0.5
                    ]);
                }
                */
            }

            return response()->json([
                'success' => true,
                'message' => $isFirstObservation ? '¡Primera observación de esta especie en esta área! +'.$points.' puntos' : 'Observación registrada. +'.$points.' puntos',
                'data' => $observation->load(['taxon', 'photos', 'user:id,username']),
                'points_earned' => $points
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error registrando observación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar detalles de una observación
     */
    public function show(int $id): JsonResponse
    {
        try {
            $observation = Observation::with(['taxon', 'photos', 'user:id,username'])->findOrFail($id);
            $points = PointTransaction::where('reference_type', 'observation')
                ->where('reference_id', $id)
                ->value('points') ?? 0;

            return response()->json([
                'success' => true,
                'data' => (object) [
                    'id' => $observation->id,
                    'taxon_id' => $observation->taxon_id,
                    'scientific_name' => $observation->taxon?->scientific_name,
                    'common_name' => $observation->taxon?->common_name,
                    'latitude' => $observation->latitude,
                    'longitude' => $observation->longitude,
                    'observed_at' => $observation->observed_at,
                    'description' => $observation->description,
                    'location_name' => $observation->location_name,
                    'location_description' => $observation->location_description,
                    'weather_conditions' => $observation->weather_conditions,
                    'temperature' => $observation->temperature,
                    'humidity' => $observation->humidity,
                    'photos' => $observation->photos,
                    'user' => $observation->user->username,
                    'points_earned' => $points,
                    'is_first_observation' => PointTransaction::where('reference_type', 'observation')
                        ->where('reference_id', $id)
                        ->where('description', 'Primera observación de especie')
                        ->exists()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo observación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Listar observaciones del usuario autenticado
     */
    public function myObservations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:50',
            'status' => Rule::in(['sin_identificar', 'sugerida', 'confirmada', 'controvertida']),
            'date_from' => 'date',
            'date_to' => 'date|after_or_equal:date_from',
            'sort_by' => Rule::in(['created_at', 'observed_at', 'quality_score']),
            'sort_order' => Rule::in(['asc', 'desc']),
            'location_id' => 'nullable|integer|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $user = $request->user();
            $locationId = $request->input('location_id', $this->getDefaultLocationId());
            
            $query = Observation::with(['taxon', 'photos'])
                ->where('user_id', $user->id);
                
            if ($locationId) {
                $query->where('location_id', $locationId);
            }

            if ($request->has('status')) {
                $query->where('identification_status', $request->input('status'));
            }

            if ($request->has('date_from')) {
                $query->whereDate('observed_at', '>=', $request->input('date_from'));
            }

            if ($request->has('date_to')) {
                $query->whereDate('observed_at', '<=', $request->input('date_to'));
            }

            $sortBy = $request->input('sort_by', 'observed_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $perPage = $request->input('per_page', 20);
            $observations = $query->paginate($perPage);

            // Construir consultas para estadísticas
            $baseQuery = $user->observations();
            if ($locationId) {
                $baseQuery->where('location_id', $locationId);
            }

            $stats = [
                'total_observations' => (clone $baseQuery)->count(),
                'identified_count' => (clone $baseQuery)->whereNotNull('taxon_id')->count(),
                'research_grade_count' => (clone $baseQuery)->where('is_research_grade', true)->count(),
                'avg_quality_score' => round((clone $baseQuery)->avg('quality_score') ?? 0, 2),
                'species_observed' => (clone $baseQuery)->whereNotNull('taxon_id')->distinct('taxon_id')->count(),
                'this_month_count' => (clone $baseQuery)->whereMonth('observed_at', now()->month)->count(),
            ];

            // Calcular total de puntos con join más específico
            if ($locationId) {
                $stats['total_points'] = PointTransaction::where('point_transactions.user_id', $user->id)
                    ->where('point_transactions.reference_type', 'observation')
                    ->join('observations', 'point_transactions.reference_id', '=', 'observations.id')
                    ->where('observations.location_id', $locationId)
                    ->sum('point_transactions.points');
            } else {
                $stats['total_points'] = PointTransaction::where('user_id', $user->id)
                    ->where('reference_type', 'observation')
                    ->sum('points');
            }

            return response()->json([
                'success' => true,
                'data' => $observations->getCollection()->map(function ($obs) {
                    $points = PointTransaction::where('reference_type', 'observation')
                        ->where('reference_id', $obs->id)
                        ->value('points') ?? 0;
                    return (object) [
                        'id' => $obs->id,
                        'taxon_id' => $obs->taxon_id,
                        'scientific_name' => $obs->taxon?->scientific_name,
                        'common_name' => $obs->taxon?->common_name,
                        'observed_at' => $obs->observed_at,
                        'photos' => $obs->photos,
                        'points_earned' => $points
                    ];
                })->values(),
                'meta' => [
                    'current_page' => $observations->currentPage(),
                    'last_page' => $observations->lastPage(),
                    'per_page' => $observations->perPage(),
                    'total' => $observations->total()
                ],
                'user_stats' => $stats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo mis observaciones: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Subir una foto para una observación
     */
    public function uploadPhoto(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'photo' => 'required|image|mimes:jpeg,png,jpg|max:2048',
            'caption' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $observation = Observation::findOrFail($id);
            if ($observation->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para modificar esta observación'
                ], 403);
            }

            $photo = $this->observationService->uploadPhoto($observation, $request->file('photo'), $request->input('caption'));

            // Otorgar puntos por subir foto
            PointTransaction::create([
                'user_id' => Auth::id(),
                'points' => 5, // Puntos por subir una foto
                'transaction_type' => 'observacion',
                'reference_id' => $observation->id,
                'reference_type' => 'observation',
                'description' => 'Subir foto a observación'
            ]);

            $user = Auth::user();
            $user->increment('total_score', 5);
            $user->increment('experience_points', 5);

            // Verificar logros
            $this->achievementService->checkAchievements($user, $observation);

            return response()->json([
                'success' => true,
                'message' => 'Foto subida correctamente. +5 puntos',
                'data' => $photo
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error subiendo foto: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una foto
     */
    public function deletePhoto(int $id): JsonResponse
    {
        try {
            $photo = ObservationPhoto::findOrFail($id);
            $observation = $photo->observation;

            if ($observation->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar esta foto'
                ], 403);
            }

            $this->observationService->deletePhoto($photo);

            return response()->json([
                'success' => true,
                'message' => 'Foto eliminada correctamente'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error eliminando foto: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Establecer foto primaria
     */
    public function setPrimaryPhoto(int $id): JsonResponse
    {
        try {
            $photo = ObservationPhoto::findOrFail($id);
            $observation = $photo->observation;

            if ($observation->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para modificar esta observación'
                ], 403);
            }

            $this->observationService->setPrimaryPhoto($observation, $photo);

            return response()->json([
                'success' => true,
                'message' => 'Foto establecida como primaria'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error estableciendo foto primaria: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Tabla de clasificación
     */
    public function leaderboard(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'per_page' => 'integer|min:1|max:50',
            'location_id' => 'nullable|integer|exists:locations,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $perPage = $request->input('per_page', 10);
            $locationId = $request->input('location_id', $this->getDefaultLocationId());

            $query = User::select('users.id', 'users.username', 'users.total_score');

            if ($locationId) {
                $query->join('point_transactions', 'users.id', '=', 'point_transactions.user_id')
                    ->join('observations', 'point_transactions.reference_id', '=', 'observations.id')
                    ->where('point_transactions.reference_type', 'observation')
                    ->where('observations.location_id', $locationId)
                    ->groupBy('users.id', 'users.username', 'users.total_score');
            }

            $leaderboard = $query->orderBy('total_score', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $leaderboard->items(),
                'meta' => [
                    'current_page' => $leaderboard->currentPage(),
                    'last_page' => $leaderboard->lastPage(),
                    'per_page' => $leaderboard->perPage(),
                    'total' => $leaderboard->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error obteniendo tabla de clasificación: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener el ID de ubicación por defecto
     */
    private function getDefaultLocationId(): ?int
    {
        // Puedes configurar esto de varias maneras:
        // 1. Desde config/app.php
        $configLocationId = config('app.campus_location_id');
        if ($configLocationId) {
            return $configLocationId;
        }

        // 2. Desde variables de entorno
        $envLocationId = env('DEFAULT_LOCATION_ID');
        if ($envLocationId) {
            return (int) $envLocationId;
        }

        // 3. Desde la primera ubicación disponible
        $firstLocation = Location::first();
        if ($firstLocation) {
            return $firstLocation->id;
        }

        // 4. Si no hay ubicaciones, devolver null
        return null;
    }
}