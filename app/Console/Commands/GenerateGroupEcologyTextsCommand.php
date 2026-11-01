<?php

namespace App\Console\Commands;

use App\Jobs\GenerateGroupEcologyTextJob;
use App\Models\GroupEcologyText;
use App\Models\SpeciesEcologyText;
use App\Models\Taxa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

/**
 * Encola textos ecológicos de géneros y familias, empezando por los que
 * cubren más especies sin texto propio. Con pocos textos casi ninguna ficha
 * queda vacía. Corre por lotes pequeños igual que ecology:generate.
 */
class GenerateGroupEcologyTextsCommand extends Command
{
    protected $signature = 'ecology:groups
        {--limit=10 : Grupos a encolar}
        {--rank= : Solo genus o family}';

    protected $description = 'Encola la redacción con IA de la importancia ecológica de géneros y familias';

    public function handle(): int
    {
        if (Queue::size('ecology-batch') > 0) {
            $this->info('La cola ecology-batch aún tiene trabajo; no se encola más.');

            return self::SUCCESS;
        }

        $ranks = $this->option('rank') ? [$this->option('rank')] : ['family', 'genus'];
        $limit = max(1, (int) $this->option('limit'));
        $queued = collect();

        foreach ($ranks as $rank) {
            if (! in_array($rank, GroupEcologyText::RANKS, true) || $queued->count() >= $limit) {
                continue;
            }

            Taxa::query()
                ->whereNotNull($rank)
                ->whereNotIn('id', SpeciesEcologyText::query()->select('taxon_id'))
                ->whereNotIn($rank, GroupEcologyText::query()->where('rank', $rank)->select('name'))
                ->groupBy($rank)
                ->orderByRaw('COUNT(*) DESC')
                ->limit($limit - $queued->count())
                ->pluck($rank)
                ->each(function (string $name) use ($rank, $queued) {
                    GenerateGroupEcologyTextJob::dispatch($rank, $name);
                    $queued->push("{$rank}: {$name}");
                });
        }

        $queued->each(fn (string $group) => $this->line("Programado: {$group}"));
        $this->info("Encolados {$queued->count()} grupos.");

        return self::SUCCESS;
    }
}
