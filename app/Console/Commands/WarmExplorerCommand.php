<?php

namespace App\Console\Commands;

use App\Services\Biodiversity\ExplorerService;
use Illuminate\Console\Command;

/**
 * Precalienta el explorador nacional: primera página de cada grupo (iNaturalist)
 * y su complemento de registros científicos (GBIF). Así la primera persona del
 * día que abre un filtro no espera a las APIs externas.
 */
class WarmExplorerCommand extends Command
{
    protected $signature = 'explorer:warm';

    protected $description = 'Precalcula las listas nacionales del explorador por grupo';

    public function handle(ExplorerService $explorer): int
    {
        foreach (array_merge([null], ExplorerService::GROUPS) as $group) {
            $filters = ['group' => $group, 'order' => 'popular', 'page' => 1, 'per_page' => 24];
            $started = microtime(true);

            $explorer->citizenScience($filters);
            $explorer->scientificRecords($filters);

            $this->line(sprintf('%-15s %5.1f s', $group ?? 'Todos', microtime(true) - $started));
        }

        return self::SUCCESS;
    }
}
