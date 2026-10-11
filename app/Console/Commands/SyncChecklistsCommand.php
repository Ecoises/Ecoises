<?php

namespace App\Console\Commands;

use App\Models\Taxa;
use App\Services\Biodiversity\ConservationLabelService;
use Illuminate\Console\Command;

/**
 * Descarga las listas oficiales de Colombia (amenazadas, endémicas e
 * introducidas) y la categoría UICN de cada especie, todo desde GBIF.
 */
class SyncChecklistsCommand extends Command
{
    protected $signature = 'checklists:sync {--iucn-all : Volver a consultar la UICN para todas las especies, no solo las pendientes}';

    protected $description = 'Actualiza listas oficiales de Colombia y categorías UICN (GBIF, sin token)';

    public function handle(ConservationLabelService $labels): int
    {
        foreach ($labels->syncLists() as $list => $count) {
            $this->line(sprintf('%-18s %5d especies', $list, $count));
        }

        $query = Taxa::whereNotNull('gbif_taxon_key');
        if (! $this->option('iucn-all')) {
            $query->whereNull('iucn_synced_at');
        }

        $updated = 0;
        $query->chunkById(500, function ($taxa) use ($labels, &$updated) {
            $updated += $labels->syncIucn($taxa);
        });
        $this->info("Categoría UICN consultada para {$updated} especies.");

        return self::SUCCESS;
    }
}
