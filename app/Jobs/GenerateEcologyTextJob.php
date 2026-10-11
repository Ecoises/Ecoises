<?php

namespace App\Jobs;

use App\Models\Taxa;
use App\Services\Biodiversity\EcologicalImportanceService;
use App\Services\Biodiversity\EcologyTextService;
use App\Services\Biodiversity\TaxonSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Redacta el texto de importancia ecológica de una especie (una sola vez).
 * Única por especie: si muchas personas abren la misma ficha nueva a la vez,
 * se genera un solo texto.
 */
class GenerateEcologyTextJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [120];

    public $timeout = 90;

    public $uniqueFor = 3600;

    public function __construct(public int $taxonId)
    {
        $this->onQueue('species-sync');
    }

    public function uniqueId(): string
    {
        return (string) $this->taxonId;
    }

    public function handle(EcologyTextService $texts, EcologicalImportanceService $ecology, TaxonSyncService $sync): void
    {
        $taxon = Taxa::with('apiReferences')->find($this->taxonId);
        if (! $taxon || ! $texts->needsText($taxon)) {
            return;
        }

        // Especies que llegaron desde el explorador sin taxonomía completa:
        // se completa con iNaturalist (familia, género, resumen de Wikipedia).
        if ((! $taxon->family || ! $taxon->genus) && $taxon->inat_taxon_id) {
            $sync->enrichFromINaturalistId((string) $taxon->inat_taxon_id);
            $taxon = Taxa::with('apiReferences')->find($this->taxonId);
        }

        // Antes de redactar, asegura las interacciones (de la especie o su grupo).
        if ($ecology->needsSync($taxon) || $ecology->present($taxon) === null) {
            $ecology->sync($taxon);
            $taxon->load('apiReferences');
        }

        if ($texts->reserveDailySlot()) {
            $texts->generate($taxon);
        }
    }
}
