<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Biodiversity\ExplorerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Explorador de especies.
 *
 *  GET /api/explorer             → lista principal (iNaturalist, con foto)
 *  GET /api/explorer/scientific  → todas las especies con registros en GBIF
 *
 * Ambos aceptan los mismos filtros. Sin lat/lng el alcance es todo Colombia.
 */
class ExplorerController extends Controller
{
    public function __construct(protected ExplorerService $explorer) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->explorer->citizenScience($this->filters($request));

        return $this->respond($result);
    }

    public function scientific(Request $request): JsonResponse
    {
        $result = $this->explorer->scientificRecords($this->filters($request));

        return $this->respond($result);
    }

    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'lat' => 'nullable|required_with:lng|numeric|between:-90,90',
            'lng' => 'nullable|required_with:lat|numeric|between:-180,180',
            'radius' => 'sometimes|integer|min:1|max:200',
            'group' => ['sometimes', 'nullable', Rule::in(ExplorerService::GROUPS)],
            'q' => 'sometimes|nullable|string|max:120',
            'native' => 'sometimes|boolean',
            'endemic' => 'sometimes|boolean',
            'threatened' => 'sometimes|boolean',
            'order' => 'sometimes|in:popular,random',
            'seed' => 'sometimes|nullable|string|max:80',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:6|max:50',
        ]);

        return [
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,
            'radius' => (int) ($validated['radius'] ?? 25),
            'group' => $validated['group'] ?? null,
            'q' => $validated['q'] ?? null,
            'native' => $request->boolean('native'),
            'endemic' => $request->boolean('endemic'),
            'threatened' => $request->boolean('threatened'),
            'order' => $validated['order'] ?? 'popular',
            'seed' => $validated['seed'] ?? null,
            'page' => (int) ($validated['page'] ?? 1),
            'per_page' => (int) ($validated['per_page'] ?? 24),
        ];
    }

    private function respond(array $result): JsonResponse
    {
        if (! ($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'No pudimos consultar las especies en este momento. Intenta de nuevo.',
                'data' => [],
            ], 502);
        }

        return response()->json($result);
    }
}
