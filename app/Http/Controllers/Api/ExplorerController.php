<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExplorerService;
use Illuminate\Http\Request;

class ExplorerController extends Controller
{
    public function __construct(protected ExplorerService $explorerService)
    {
    }

    public function explore(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric|min:-90|max:90',
            'lng' => 'required|numeric|min:-180|max:180',
            'radius' => 'sometimes|integer|min:1|max:200',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:10|max:50',
            'q' => 'sometimes|nullable|string|max:120',
            'iconic_taxa' => 'sometimes|nullable|string|max:80',
            'native' => 'sometimes|in:true,false,1,0',
            'endemic' => 'sometimes|in:true,false,1,0',
            'threatened' => 'sometimes|in:true,false,1,0',
            'order_by' => 'sometimes|in:observations_count,occurrence_count,observed_on,random',
            'random_seed' => 'sometimes|nullable|string|max:80',
        ]);

        $result = $this->explorerService->explore(
            (float) $request->input('lat'),
            (float) $request->input('lng'),
            [
                'radius' => (int) $request->input('radius', 50),
                'page' => (int) $request->input('page', 1),
                'per_page' => (int) $request->input('per_page', 25),
                'q' => $request->input('q'),
                'iconic_taxa' => $request->input('iconic_taxa'),
                'native' => $request->boolean('native'),
                'endemic' => $request->boolean('endemic'),
                'threatened' => $request->boolean('threatened'),
                'order_by' => $request->input('order_by', 'occurrence_count'),
                'random_seed' => $request->input('random_seed'),
            ]
        );

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? $result['error'] ?? 'Error al explorar especies',
                'data' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }
    public function national(Request $request)
    {
        $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:10|max:50',
            'q' => 'sometimes|nullable|string|max:120',
            'iconic_taxa' => 'sometimes|nullable|string|max:80',
            'native' => 'sometimes|in:true,false,1,0',
            'endemic' => 'sometimes|in:true,false,1,0',
            'threatened' => 'sometimes|in:true,false,1,0',
            'order_by' => 'sometimes|in:observations_count,occurrence_count,observed_on,random',
            'random_seed' => 'sometimes|nullable|string|max:80',
        ]);

        $result = $this->explorerService->exploreNational([
            'page' => (int) $request->input('page', 1),
            'per_page' => (int) $request->input('per_page', 25),
            'q' => $request->input('q'),
            'iconic_taxa' => $request->input('iconic_taxa'),
            'native' => $request->boolean('native'),
            'endemic' => $request->boolean('endemic'),
            'threatened' => $request->boolean('threatened'),
            'order_by' => $request->input('order_by', 'occurrence_count'),
            'random_seed' => $request->input('random_seed'),
        ]);

        if (!($result['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['error']['message'] ?? $result['error'] ?? 'Error al explorar Colombia',
                'data' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'],
        ]);
    }
}