<?php

namespace App\Console\Commands;

use App\Jobs\FetchSpeciesGalleryJob;
use App\Models\Taxa;
use App\Services\Biodiversity\TaxonCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * Encola la galería de fotos de las especies que solo tienen su foto
 * principal, empezando por las más observadas. Corre por lotes pequeños
 * mientras el servidor está encendido, para que la ficha ya la tenga cuando
 * alguien la abra.
 */
class FillSpeciesGalleriesCommand extends Command
{
    protected $signature = 'species:fill-galleries {--limit=20 : Especies a encolar}';

    protected $description = 'Completa en segundo plano las galerías de fotos vacías desde iNaturalist';

    public function handle(TaxonCatalogService $catalog): int
    {
        if (Queue::size('ecology-batch') > 0) {
            $this->info('La cola ecology-batch aún tiene trabajo; no se encola más.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $queued = 0;

        Taxa::with('apiReferences')
            ->whereHas('apiReferences', fn ($reference) => $reference->where('api_source', 'inaturalist'))
            ->orderByDesc('observation_count')
            ->orderBy('id')
            ->lazy(200)
            ->filter(fn (Taxa $taxon) => $catalog->needsGallery($taxon))
            ->take($limit)
            ->each(function (Taxa $taxon) use (&$queued) {
                FetchSpeciesGalleryJob::dispatch($taxon->id);
                $queued++;
            });

        $this->info("Encoladas {$queued} especies.");

        return self::SUCCESS;
    }
}
