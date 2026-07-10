<?php

namespace App\Services;

use App\Models\Taxa;
use App\Services\Api\GbifService;
use App\Jobs\EnrichSpeciesJob;
use Illuminate\Support\Facades\Cache;

class ExplorerService
{
    protected GbifService $gbif;

    public function __construct(GbifService $gbif)
    {
        $this->gbif = $gbif;
    }

    public function explore(float $lat, float $lng, array $filters = []): array
    {
        $radius = (int)($filters['radius'] ?? 50);
        $gbifCacheKey = "explorer_gbif:{$lat}:{$lng}:{$radius}";

        $speciesMap = Cache::remember($gbifCacheKey, 1800, function () use ($lat, $lng, $radius) {
            return $this->fetchSpeciesFromGbif($lat, $lng, $radius);
        });

        if (!$speciesMap) {
            return ['success' => false, 'error' => 'Error al consultar GBIF'];
        }

        $keyToId = [];
        $toEnrich = [];

        foreach ($speciesMap as $speciesKey => $sp) {
            $taxon = Taxa::firstOrCreate(
                ['scientific_name' => $sp['scientific_name']],
                ['sync_status' => 'pending']
            );

            if ($taxon->sync_status !== 'synced') {
                $taxon->timestamps = false;
                $update = ['gbif_taxon_key' => $speciesKey];
                foreach (['kingdom', 'phylum', 'class', 'order', 'family', 'genus', 'species'] as $field) {
                    $dbField = $field === 'order' ? 'order_name' : $field;
                    if (empty($taxon->$dbField) && !empty($sp['taxonomy'][$field])) {
                        $update[$dbField] = $sp['taxonomy'][$field];
                    }
                }
                $taxon->fill($update);
                $taxon->save();
                $taxon->timestamps = true;
            }

            $keyToId[$speciesKey] = $taxon->id;

            if ($taxon->sync_status !== 'synced') {
                $toEnrich[] = $sp['scientific_name'];
            }
        }

        $taxaById = Taxa::with('apiReferences')
            ->whereIn('id', array_values($keyToId))
            ->get()
            ->keyBy('id');

        $results = [];
        foreach ($speciesMap as $speciesKey => $sp) {
            $taxon = $taxaById->get($keyToId[$speciesKey]);
            if (!$taxon) continue;

            $item = $taxon->enriched_data;
            $item['occurrence_count'] = $sp['occurrence_count'];
            $results[] = $item;
        }

        foreach ($toEnrich as $name) {
            EnrichSpeciesJob::dispatch($name)->onQueue('species-sync');
        }

        return [
            'success' => true,
            'data' => $results,
            'enriching_count' => count($toEnrich),
            'total_species' => count($speciesMap),
            'source' => 'gbif',
        ];
    }

    protected function fetchSpeciesFromGbif(float $lat, float $lng, float $radius): ?array
    {
        $gbifResult = $this->gbif->searchOccurrencesByRegion($lat, $lng, $radius);

        if (!$gbifResult['success']) {
            return null;
        }

        $occurrences = $gbifResult['data']['results'] ?? [];
        $facetCounts = $this->extractFacetCounts($gbifResult['data']['facets'] ?? []);

        $speciesMap = [];
        foreach ($occurrences as $occ) {
            $key = $occ['speciesKey'] ?? null;
            $raw = $occ['species'] ?? $occ['scientificName'] ?? null;
            $name = $raw ? SpeciesMerger::stripAuthorship($raw) : null;
            if (!$key || !$name || isset($speciesMap[$key])) continue;
            if (preg_match('/\b(sp\.|spec\.?|spp\.|cf\.|aff\.)\b/i', $name)) continue;

            $speciesMap[$key] = [
                'taxonomy' => [
                    'kingdom' => $occ['kingdom'] ?? null,
                    'phylum' => $occ['phylum'] ?? null,
                    'class' => $occ['class'] ?? null,
                    'order' => $occ['order'] ?? null,
                    'family' => $occ['family'] ?? null,
                    'genus' => $occ['genus'] ?? null,
                    'species' => $occ['species'] ?? null,
                ],
                'scientific_name' => $name,
                'occurrence_count' => $facetCounts[$key] ?? 0,
            ];
        }

        return $speciesMap;
    }

    protected function extractFacetCounts(array $facets): array
    {
        $counts = [];
        foreach ($facets as $facet) {
            if (($facet['field'] ?? '') === 'speciesKey' || ($facet['field'] ?? '') === '') {
                foreach ($facet['counts'] ?? [] as $entry) {
                    $counts[(string)$entry['name']] = (int)($entry['count'] ?? 0);
                }
            }
        }
        return $counts;
    }
}
