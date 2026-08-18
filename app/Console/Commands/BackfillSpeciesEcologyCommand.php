<?php

namespace App\Console\Commands;

use App\Jobs\EnrichSpeciesEcologyJob;
use App\Models\Taxa;
use Illuminate\Console\Command;

class BackfillSpeciesEcologyCommand extends Command
{
    protected $signature = 'species:enrich-ecology
        {--id= : ID local de una especie}
        {--limit=100 : Máximo de especies que se enviarán a la cola}
        {--refresh : Volver a consultar especies ya enriquecidas}
        {--sync : Ejecutar inmediatamente en lugar de usar la cola}';

    protected $description = 'Enriquece perfiles ecológicos desde Encyclopedia of Life';

    public function handle(): int
    {
        $query = Taxa::query()->orderBy('id');

        if ($this->option('id')) {
            $query->whereKey((int) $this->option('id'));
        } elseif (!$this->option('refresh')) {
            $query->whereDoesntHave('apiReferences', fn ($reference) => $reference->where('api_source', 'eol'));
        }

        $taxa = $query->limit(max(1, (int) $this->option('limit')))->get(['id', 'scientific_name']);

        foreach ($taxa as $taxon) {
            if ($this->option('sync')) {
                EnrichSpeciesEcologyJob::dispatchSync($taxon->id);
            } else {
                EnrichSpeciesEcologyJob::dispatch($taxon->id);
            }

            $this->line("Programado: {$taxon->scientific_name} (#{$taxon->id})");
        }

        $this->info("Total: {$taxa->count()} especies.");

        return self::SUCCESS;
    }
}
