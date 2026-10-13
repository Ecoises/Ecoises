<?php

namespace App\Console\Commands;

use App\Jobs\EnrichSpeciesJob;
use App\Models\Taxa;
use App\Services\Biodiversity\SpeciesMerger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quita el autor de los nombres científicos guardados ("Quiscalus lugubris
 * Swainson, 1838" → "Quiscalus lugubris"). Si ya existe la especie con el
 * nombre limpio, une las dos: pasa sus observaciones, retos y referencias a la
 * limpia y borra la repetida. Así iNaturalist encuentra la especie y su foto.
 */
class CleanScientificNamesCommand extends Command
{
    protected $signature = 'taxa:clean-names {--dry-run : Solo muestra lo que cambiaría}';

    protected $description = 'Quita el autor de los nombres científicos y une las especies repetidas';

    /** Columnas que se copian a la especie limpia si allá están vacías. */
    private const FILL = ['common_name', 'gbif_taxon_key', 'inat_taxon_id', 'kingdom', 'phylum', 'class', 'order_name', 'family', 'genus', 'species', 'taxon_author', 'iucn_category', 'iucn_taxon_id'];

    /** Tablas que apuntan a `taxa` con una columna taxon_id sin restricción de unicidad. */
    private const MOVE = ['observations', 'identifications', 'challenges'];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $renamed = $merged = 0;
        $toEnrich = [];

        Taxa::query()->lazyById(200)->each(function (Taxa $taxon) use ($dryRun, &$renamed, &$merged, &$toEnrich) {
            $raw = $taxon->getRawOriginal('scientific_name');
            $clean = SpeciesMerger::stripAuthorship($raw);
            if ($clean === $raw) {
                return;
            }

            $twin = Taxa::where('scientific_name', $clean)->where('id', '!=', $taxon->id)->first();
            $this->line(($twin ? "  unir   #{$taxon->id} → #{$twin->id}" : "  renombrar #{$taxon->id}")." · {$raw} → {$clean}");

            if ($dryRun) {
                $twin ? $merged++ : $renamed++;

                return;
            }

            if ($twin) {
                $this->merge($taxon, $twin, $raw);
                $merged++;
                $toEnrich[] = $twin;
            } else {
                $taxon->scientific_name = $raw; // el mutador deja el nombre limpio y guarda el autor
                $taxon->sync_status = 'pending';
                $taxon->save();
                $renamed++;
                $toEnrich[] = $taxon;
            }
        });

        // Las que quedaron sin datos de iNaturalist (sin foto) se completan en segundo plano.
        foreach ($toEnrich as $taxon) {
            if (! $taxon->apiReferences()->where('api_source', 'inaturalist')->exists()) {
                EnrichSpeciesJob::dispatch($taxon->scientific_name);
            }
        }

        $verb = $dryRun ? 'Se unirían' : 'Unidas';
        $this->info("{$verb} {$merged} especies repetidas y ".($dryRun ? 'se renombrarían' : 'renombradas')." {$renamed}.");

        return self::SUCCESS;
    }

    private function merge(Taxa $duplicate, Taxa $twin, string $rawName): void
    {
        DB::transaction(function () use ($duplicate, $twin, $rawName) {
            $values = $duplicate->getAttributes();
            $values['taxon_author'] = $values['taxon_author'] ?: SpeciesMerger::authorship($rawName);

            // La clave de GBIF es única por especie: se libera antes de pasarla.
            $gbifKey = $duplicate->gbif_taxon_key;
            $duplicate->forceFill(['gbif_taxon_key' => null])->saveQuietly();

            foreach (self::FILL as $column) {
                if (blank($twin->getAttribute($column)) && ! blank($values[$column] ?? null)) {
                    $twin->setAttribute($column, $column === 'gbif_taxon_key' ? $gbifKey : $values[$column]);
                }
            }
            $twin->saveQuietly();

            foreach (array_filter(self::MOVE, fn (string $table) => Schema::hasTable($table)) as $table) {
                DB::table($table)->where('taxon_id', $duplicate->id)->update(['taxon_id' => $twin->id]);
            }

            // Referencias a APIs y textos de ecología: se pasan solo si la limpia no los tiene.
            foreach (DB::table('taxon_api_references')->where('taxon_id', $duplicate->id)->get() as $reference) {
                $exists = DB::table('taxon_api_references')->where('taxon_id', $twin->id)->where('api_source', $reference->api_source)->exists();
                $exists
                    ? DB::table('taxon_api_references')->where('id', $reference->id)->delete()
                    : DB::table('taxon_api_references')->where('id', $reference->id)->update(['taxon_id' => $twin->id]);
            }

            if (! DB::table('species_ecology_texts')->where('taxon_id', $twin->id)->exists()) {
                DB::table('species_ecology_texts')->where('taxon_id', $duplicate->id)->update(['taxon_id' => $twin->id]);
            }

            DB::table('unified_api_caches')->where('taxon_id', $duplicate->id)->delete();
            $duplicate->delete();
        });
    }
}
