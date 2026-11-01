<?php

namespace App\Jobs;

use App\Models\Taxa;
use App\Services\Biodiversity\TaxonCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Trae de iNaturalist la ficha completa de una especie que solo tiene su foto
 * principal, para guardar su galería. Va en segundo plano: la ficha no espera.
 */
class FetchSpeciesGalleryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [60];

    public $timeout = 60;

    public $uniqueFor = 3600;

    public function __construct(public int $taxonId)
    {
        $this->onQueue('ecology-batch');
    }

    public function uniqueId(): string
    {
        return (string) $this->taxonId;
    }

    public function handle(TaxonCatalogService $catalog): void
    {
        $taxon = Taxa::with('apiReferences')->find($this->taxonId);

        if ($taxon && $catalog->needsGallery($taxon)) {
            $catalog->fetchGallery($taxon);
        }
    }
}
