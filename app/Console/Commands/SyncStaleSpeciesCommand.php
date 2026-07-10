<?php

namespace App\Console\Commands;

use App\Jobs\EnrichSpeciesJob;
use App\Models\Taxa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncStaleSpeciesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'species:sync-stale
                            {--days=30 : Días sin sincronizar para considerar "stale"}
                            {--chunk=50 : Tamaño del lote para enqueue}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Enqueue jobs para re-enriquecer especies desactualizadas o que nunca fueron sincronizadas';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $chunkSize = (int) $this->option('chunk');

        $this->info("🔄 Buscando especies desactualizadas (última sync > {$days} días)...");

        $query = Taxa::where(function ($q) use ($days) {
            $q->where('last_synced_at', '<', now()->subDays($days))
              ->orWhereNull('last_synced_at');
        })
        ->where('sync_status', '!=', 'syncing');

        $count = $query->count();
        $this->info("📊 Encontradas {$count} especies para sincronizar");

        if ($count === 0) {
            $this->info("✅ Catálogo actualizado");
            return self::SUCCESS;
        }

        $enqueued = 0;

        $query->chunkById($chunkSize, function ($taxa) use (&$enqueued) {
            foreach ($taxa as $taxon) {
                EnrichSpeciesJob::dispatch($taxon->scientific_name)
                    ->onQueue('species-sync');
                $enqueued++;
            }
            $this->line("  ✓ Enqueued: {$enqueued}");
        });

        Log::info('SyncStaleSpeciesCommand completed', [
            'enqueued' => $enqueued,
            'days' => $days,
        ]);

        $this->info("\n" . str_repeat('=', 60));
        $this->info("✅ Sincronización programada:");
        $this->line("  • Enqueued: {$enqueued}");
        $this->info("  Procesa con: php artisan queue:work --queue=species-sync");
        $this->info(str_repeat('=', 60));

        return self::SUCCESS;
    }
}
