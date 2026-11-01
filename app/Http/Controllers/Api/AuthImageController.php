<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\WarmAuthImagesJob;
use App\Services\Biodiversity\AuthImageService;
use Illuminate\Http\JsonResponse;

/**
 * GET /auth-image — foto variada y en alta resolución para el login y el registro.
 */
class AuthImageController extends Controller
{
    public function show(AuthImageService $images): JsonResponse
    {
        // Responde con lo que ya hay; los grupos que faltan se llenan en segundo plano.
        if ($images->missingGroups() !== []) {
            WarmAuthImagesJob::dispatch();
        }

        $image = $images->random();

        return $image ? response()->json($image) : response()->json(null, 503);
    }
}
