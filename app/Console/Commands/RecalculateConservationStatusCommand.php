<?php

namespace App\Console\Commands;

use App\Models\Taxa;
use App\Services\ConservationStatusResolver;
use Illuminate\Console\Command;

class RecalculateConservationStatusCommand extends Command
{
    protected $signature = 'conservation:recalculate {--dry-run : Report changes without saving them}';

    protected $description = 'Recalculate conservation status and provenance from cached iNaturalist data';

    public function handle(ConservationStatusResolver $resolver): int
    {
        $placeId = (int) config('services.inaturalist.preferred_place_id', 7196);
        $stats = ['reviewed' => 0, 'updated' => 0, 'cleared_ne' => 0, 'preserved_legacy' => 0];

        Taxa::query()
            ->whereHas('apiReferences', fn ($query) => $query->where('api_source', 'inaturalist'))
            ->with(['apiReferences' => fn ($query) => $query->where('api_source', 'inaturalist')])
            ->chunkById(200, function ($taxa) use ($resolver, $placeId, &$stats) {
                foreach ($taxa as $taxon) {
                    $stats['reviewed']++;
                    $reference = $taxon->apiReferences->first();
                    $data = $reference?->data ?? [];
                    $resolved = $resolver->resolve(
                        $data['conservation_statuses'] ?? $data['conservation_status'] ?? null,
                        $placeId
                    );

                    if ($resolved['code']) {
                        $taxon->fill([
                            'conservation_status' => $resolved['code'],
                            'conservation_status_source' => 'inaturalist',
                            'conservation_status_scope' => $resolved['scope'],
                            'conservation_status_authority' => $resolved['authority'],
                            'conservation_status_url' => $resolved['url'],
                        ]);
                    } elseif ($taxon->conservation_status === 'NE') {
                        $taxon->fill([
                            'conservation_status' => null,
                            'conservation_status_source' => 'inaturalist',
                            'conservation_status_scope' => null,
                            'conservation_status_authority' => null,
                            'conservation_status_url' => null,
                        ]);
                        $stats['cleared_ne']++;
                    } elseif ($taxon->conservation_status && !$taxon->conservation_status_source) {
                        $taxon->conservation_status_source = 'legacy';
                        $stats['preserved_legacy']++;
                    }

                    if ($taxon->isDirty([
                        'conservation_status',
                        'conservation_status_source',
                        'conservation_status_scope',
                        'conservation_status_authority',
                        'conservation_status_url',
                    ])) {
                        $taxon->conservation_status_synced_at = now();
                        $stats['updated']++;
                        if (!$this->option('dry-run')) {
                            $taxon->timestamps = false;
                            $taxon->save();
                            $taxon->timestamps = true;
                        }
                    }
                }
            });

        $this->table(['Metric', 'Count'], collect($stats)->map(
            fn (int $count, string $metric) => [$metric, $count]
        )->values()->all());

        if ($this->option('dry-run')) {
            $this->warn('Dry run: no database rows were changed.');
        }

        return self::SUCCESS;
    }
}
