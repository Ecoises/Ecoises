<?php

namespace App\Jobs;

use App\Models\Taxa;
use App\Services\Biodiversity\EcologicalImportanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Descarga de GloBI las interacciones de una especie (importancia ecológica).
 */
class SyncSpeciesInteractionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [60, 300, 900];

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

    public function handle(EcologicalImportanceService $ecology): void
    {
        if ($taxon = Taxa::find($this->taxonId)) {
            $ecology->sync($taxon);
        }
    }
}
