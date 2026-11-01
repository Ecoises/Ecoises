<?php

namespace App\Jobs;

use App\Services\Biodiversity\AuthImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Llena en segundo plano las fotos de las pantallas de acceso que faltan
 * (un grupo por consulta a iNaturalist, unos 3 s cada uno).
 */
class WarmAuthImagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 400;

    public $uniqueFor = 600;

    public function handle(AuthImageService $images): void
    {
        foreach ($images->missingGroups() as $group) {
            $images->warm($group);
        }
    }
}
