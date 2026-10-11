<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEcologyTextJob;
use App\Models\Taxa;
use Illuminate\Console\Command;

/**
 * Encola textos de importancia ecológica para las especies más observadas que
 * aún no lo tienen. El tope diario lo controla EcologyTextService.
 */
class GenerateEcologyTextsCommand extends Command
{
    protected $signature = 'ecology:generate {--limit=200 : Especies a encolar}';

    protected $description = 'Encola la redacción con IA de la importancia ecológica de las especies más vistas';

    public function handle(): int
    {
        $ids = Taxa::query()
            ->whereDoesntHave('ecologyText')
            ->orderByDesc('observation_count')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        $ids->each(fn (int $id) => GenerateEcologyTextJob::dispatch($id));
        $this->info("Encoladas {$ids->count()} especies.");

        return self::SUCCESS;
    }
}
