<?php

namespace App\Console\Commands;

use App\Models\Taxa;
use App\Services\ExternalApi\GbifService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Actualiza la taxonomía de todo el catálogo desde GBIF (Backbone Taxonomy).
 * Consulta GBIF en paralelo por tandas y guarda en `taxa`; las fichas ya no
 * tienen que pedirla cuando alguien las abre.
 */
class SyncGbifTaxonomyCommand extends Command
{
    protected $signature = 'taxonomy:sync-gbif {--chunk=50}';

    protected $description = 'Completa reino, filo, clase, orden, familia y género de las especies con datos de GBIF';

    private const COLUMNS = ['kingdom' => 'kingdom', 'phylum' => 'phylum', 'class' => 'class', 'order' => 'order_name', 'family' => 'family', 'genus' => 'genus'];

    public function handle(GbifService $gbif): int
    {
        $this->resolveMissingKeys();
        $updated = 0;

        Taxa::whereNotNull('gbif_taxon_key')->chunkById((int) $this->option('chunk'), function ($taxa) use ($gbif, &$updated) {
            $details = $gbif->getSpeciesDetailsBatch($taxa->pluck('gbif_taxon_key')->map(fn ($key) => (string) $key)->all());

            foreach ($taxa as $taxon) {
                $detail = $details[(string) $taxon->gbif_taxon_key] ?? null;
                if (! $detail) {
                    continue;
                }

                foreach (self::COLUMNS as $field => $column) {
                    if (! empty($detail[$field])) {
                        $taxon->{$column} = $detail[$field];
                    }
                }

                if ($taxon->isDirty()) {
                    $taxon->saveQuietly();
                    $updated++;
                }
                Cache::put("taxonomy:gbif:{$taxon->id}", true, now()->addDays(90));
            }
        });

        $this->info("Taxonomía actualizada desde GBIF en {$updated} especies.");

        return self::SUCCESS;
    }

    /**
     * Especies que llegaron desde iNaturalist sin clave de GBIF: se buscan por
     * nombre en paralelo (25 a la vez). La respuesta de GBIF ya trae la
     * taxonomía, así que se guarda en el mismo paso.
     */
    private function resolveMissingKeys(): void
    {
        $resolved = 0;

        Taxa::whereNull('gbif_taxon_key')->chunkById(25, function ($taxa) use (&$resolved) {
            $responses = Http::pool(fn (Pool $pool) => $taxa->map(
                fn (Taxa $taxon) => $pool->as((string) $taxon->id)->timeout(20)
                    ->get('https://api.gbif.org/v1/species/match', ['name' => $taxon->scientific_name])
            )->all());

            foreach ($taxa as $taxon) {
                $response = $responses[(string) $taxon->id] ?? null;
                $match = $response instanceof Response && $response->successful() ? $response->json() : [];
                $isSpecies = in_array($match['matchType'] ?? 'NONE', ['EXACT', 'FUZZY'], true)
                    && in_array($match['rank'] ?? null, ['SPECIES', 'SUBSPECIES'], true);

                if (! $isSpecies) {
                    continue;
                }

                $taxon->gbif_taxon_key = (string) ($match['speciesKey'] ?? $match['usageKey']);
                foreach (self::COLUMNS as $field => $column) {
                    if (! empty($match[$field])) {
                        $taxon->{$column} = $match[$field];
                    }
                }
                $taxon->saveQuietly();
                Cache::put("taxonomy:gbif:{$taxon->id}", true, now()->addDays(90));
                $resolved++;
            }
        });

        $this->info("Claves de GBIF encontradas para {$resolved} especies.");
    }
}
