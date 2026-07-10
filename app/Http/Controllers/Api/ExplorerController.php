<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExplorerService;
use Illuminate\Http\Request;

class ExplorerController extends Controller
{
    protected ExplorerService $explorerService;

    public function __construct(ExplorerService $explorerService)
    {
        $this->explorerService = $explorerService;
    }

    public function explore(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric|min:-90|max:90',
            'lng' => 'required|numeric|min:-180|max:180',
            'radius' => 'sometimes|integer|min:5|max:200',
        ]);

        $result = $this->explorerService->explore(
            (float) $request->input('lat'),
            (float) $request->input('lng'),
            $request->only(['radius'])
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Error al explorar especies',
                'data' => null,
            ], 500);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => [
                'source' => 'gbif',
                'enriching_count' => $result['enriching_count'],
                'total_species' => $result['total_species'],
            ],
        ]);
    }
}
