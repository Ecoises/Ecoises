<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Observation;
use App\Models\ObservationPhoto;
use App\Services\GamificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ObservationController extends Controller
{
    protected GamificationService $gamification;

    public function __construct(GamificationService $gamification)
    {
        $this->gamification = $gamification;
    }

    /**
     * Listar observaciones públicas ordenadas por fecha reciente.
     */
    public function index(Request $request)
    {
        $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:50',
            'page'     => 'sometimes|integer|min:1',
            'taxon_id' => 'sometimes|integer|exists:taxa,id',
            'user_id'  => 'sometimes|integer|exists:users,id',
        ]);

        $perPage = $request->integer('per_page', 15);

        $query = Observation::with(['user:id,full_name,avatar', 'taxon:id,scientific_name,common_name', 'photos'])
            ->where('is_public', true)
            ->orderByDesc('observed_at');

        if ($request->filled('taxon_id')) {
            $query->where('taxon_id', $request->integer('taxon_id'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Observaciones obtenidas correctamente',
            'data'    => $paginator->items(),
            'meta'    => [
                'pagination' => [
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * Mostrar el detalle de una observación.
     */
    public function show(int $id)
    {
        $observation = Observation::with([
            'user:id,full_name,avatar',
            'taxon:id,scientific_name,common_name,conservation_status',
            'photos',
            'comments.user:id,full_name,avatar',
        ])->find($id);

        if (! $observation) {
            return response()->json([
                'success' => false,
                'message' => 'Observación no encontrada',
            ], 404);
        }

        if (! $observation->is_public) {
            return response()->json([
                'success' => false,
                'message' => 'Esta observación es privada',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Observación obtenida correctamente',
            'data'    => $observation,
        ]);
    }

    /**
     * Registrar un nuevo avistamiento.
     * Requiere autenticación (auth:sanctum).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'taxon_id'      => 'nullable|integer|exists:taxa,id',
            'latitude'      => 'nullable|numeric|between:-90,90',
            'longitude'     => 'nullable|numeric|between:-180,180',
            'location_name' => 'nullable|string|max:255',
            'observed_at'   => 'nullable|date',
            'description'   => 'nullable|string|max:2000',
            'notes'         => 'nullable|string|max:1000',
            'is_public'     => 'sometimes|boolean',
            'photos'        => 'nullable|array|max:5',
            'photos.*'      => 'image|mimes:jpeg,png,jpg,webp|max:8192',
        ]);

        try {
            // Crear la observación
            $observation = Observation::create([
                'user_id'       => $request->user()->id,
                'taxon_id'      => $validated['taxon_id'] ?? null,
                'latitude'      => $validated['latitude'] ?? null,
                'longitude'     => $validated['longitude'] ?? null,
                'location_name' => $validated['location_name'] ?? null,
                'observed_at'   => $validated['observed_at'] ?? now(),
                'description'   => $validated['description'] ?? null,
                'notes'         => $validated['notes'] ?? null,
                'is_public'     => $validated['is_public'] ?? true,
                'points_awarded' => 0,
            ]);

            // Procesar fotos adjuntas
            if ($request->hasFile('photos')) {
                foreach ($request->file('photos') as $index => $photo) {
                    $path = $photo->store('observations', 'public');
                    ObservationPhoto::create([
                        'observation_id' => $observation->id,
                        'photo_url'      => Storage::url($path),
                        'is_primary'     => $index === 0,
                        'caption'        => null,
                    ]);
                }
            }

            // Otorgar puntos de gamificación (50 pts por nuevo avistamiento)
            $points = 50;
            $this->gamification->awardPoints(
                $request->user(),
                $points,
                Observation::class,
                $observation->id,
                'Avistamiento registrado'
            );
            $observation->update(['points_awarded' => $points]);

            // Recargar con relaciones para la respuesta
            $observation->load(['user:id,name,avatar', 'taxon:id,scientific_name,common_name', 'photos']);

            return response()->json([
                'success' => true,
                'message' => '¡Avistamiento registrado! Se otorgaron ' . $points . ' puntos.',
                'data'    => $observation,
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error al crear observación: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error interno al registrar el avistamiento.',
            ], 500);
        }
    }
}
