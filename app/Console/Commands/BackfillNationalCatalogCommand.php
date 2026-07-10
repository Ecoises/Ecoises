<?php

namespace App\Console\Commands;

use App\Jobs\EnrichSpeciesJob;
use App\Models\Taxa;
use App\Services\Api\GbifService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillNationalCatalogCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'species:backfill-catalog
                            {--class= : Clase taxonómica específica (ej. "Aves"). Si se omite, procesa todas}
                            {--limit=100 : Límite de especies por lote}
                            {--page=1 : Página inicial para reintentos}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Realiza backfill del catálogo nacional de especies desde GBIF, paginando por clase taxonómica para mantener la paginación manejable';

    /**
     * Clases taxonómicas prioritarias a sincronizar
     * Ordenadas por número típico de especies en Colombia (descendente)
     * 
     * @var array
     */
    protected array $taxonomicClasses = [
        'Insecta',       // ~80k especies
        'Plantae',       // ~50k especies (si GBIF lo expone así)
        'Aves',          // ~1.9k especies
        'Reptilia',      // ~600 especies
        'Amphibia',      // ~800 especies
        'Mammalia',      // ~500 especies
        'Fungi',         // ~10k especies estimadas
        'Arachnida',     // ~2k especies
        'Malacostraca',  // Crustáceos
        'Actinopterygii',// Peces óseos
        'Petromyzontida', // Lampreas
        'Petromyzontida', // Anguilas
    ];

    /**
     * Execute the console command.
     */
    public function handle(GbifService $gbif): int
    {
        $this->info('🌿 Iniciando backfill del catálogo nacional desde GBIF...');

        $classFilter = $this->option('class');
        $limit = (int) $this->option('limit');
        $startPage = (int) $this->option('page');

        // Determinar clases a procesar
        $classesToProcess = $classFilter
            ? [$classFilter]
            : $this->taxonomicClasses;

        $totalEnqueued = 0;
        $totalSkipped = 0;

        foreach ($classesToProcess as $class) {
            $this->info("\n📋 Procesando clase: {$class}");

            $page = $startPage;
            $hasMore = true;
            $classTotal = 0;

            while ($hasMore) {
                try {
                    $this->line("  • Página {$page}...");

                    // Obtener lista de especies de GBIF para esta clase en Colombia
                    $result = $gbif->getSpeciesList([
                        'country' => 'CO',
                        'class' => $class,
                        'page' => $page,
                        'per_page' => $limit,
                    ]);

                    if (!$result['success']) {
                        $errorMsg = $result['error']['message'] ?? 'Desconocido';
                        $this->warn("  ⚠ Error en GBIF: {$errorMsg}");
                        break;
                    }

                    $species = $result['data'] ?? [];
                    $pagination = $result['pagination'] ?? [];
                    $hasMore = $pagination['has_more'] ?? false;

                    if (empty($species)) {
                        $this->line("  • No hay especies en esta página.");
                        break;
                    }

                    // Procesar cada especie
                    foreach ($species as $speciesData) {
                        $scientificName = $speciesData['scientific_name'] ?? null;

                        if (!$scientificName) {
                            $totalSkipped++;
                            continue;
                        }

                        // Verificar si ya existe en la BD
                        $exists = Taxa::where('scientific_name', $scientificName)
                            ->whereNotIn('sync_status', ['pending'])
                            ->exists();

                        if ($exists) {
                            $totalSkipped++;
                            continue;
                        }

                        // Enqueue job de enriquecimiento
                        EnrichSpeciesJob::dispatch($scientificName)
                            ->onQueue('species-sync');

                        $totalEnqueued++;
                        $classTotal++;
                    }

                    $this->line("  ✓ Enqueued: {$classTotal} | Total: {$totalEnqueued}");

                    $page++;

                } catch (\Throwable $e) {
                    $this->error("  ✗ Error procesando página {$page}: {$e->getMessage()}");
                    Log::error('BackfillNationalCatalogCommand error', [
                        'class' => $class,
                        'page' => $page,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }
            }

            $this->info("✅ Clase {$class}: {$classTotal} especies enqueued");
        }

        $this->info("\n" . str_repeat('=', 60));
        $this->info("📊 Backfill completado:");
        $this->line("  • Enqueued: {$totalEnqueued}");
        $this->line("  • Skipped: {$totalSkipped}");
        $this->info("  Procesa estos jobs con: php artisan queue:work --queue=species-sync");
        $this->info(str_repeat('=', 60));

        return self::SUCCESS;
    }
}
