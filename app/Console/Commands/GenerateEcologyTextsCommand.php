<?php

namespace App\Console\Commands;

use App\Jobs\GenerateEcologyTextJob;
use App\Models\Taxa;
use App\Services\Biodiversity\EcologyTextService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * Encola textos de importancia ecológica para las especies más observadas que
 * aún no lo tienen, en la cola ecology-batch (la de menor prioridad).
 * Corre cada pocos minutos mientras el servidor está encendido y no encola
 * más si la cola aún tiene trabajo: así avanza por lotes pequeños y retoma
 * donde quedó. El tope diario de lotes lo controla EcologyTextService.
 */
class GenerateEcologyTextsCommand extends Command
{
    protected $signature = 'ecology:generate {--limit=200 : Especies a encolar}';

    protected $description = 'Encola la redacción con IA de la importancia ecológica de las especies más vistas';

    public function handle(): int
    {
        if (Queue::size('ecology-batch') > 0) {
            $this->info('La cola ecology-batch aún tiene trabajo; no se encola más.');

            return self::SUCCESS;
        }

        $ids = Taxa::query()
            ->whereDoesntHave('ecologyText')
            ->orderByDesc('observation_count')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        $ids->each(fn (int $id) => GenerateEcologyTextJob::dispatch($id, EcologyTextService::POOL_BATCH)->onQueue('ecology-batch'));
        $this->info("Encoladas {$ids->count()} especies.");

        return self::SUCCESS;
    }
}
