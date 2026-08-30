<?php

namespace App\Services;

use App\Jobs\EnrichSpeciesJob;
use App\Models\Taxa;
use App\Services\Api\GbifService;
use App\Services\Api\INaturalistService;
use App\Services\SpeciesMerger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ExplorerService
{
    private const CACHE_TTL_SECONDS = 1800;
    private const THREATENED_STATUSES = ['VU', 'EN', 'CR'];
    private const SYNCHRONOUS_ENRICHMENT_LIMIT = 8;

    public function __construct(
        protected GbifService $gbif,
        protected INaturalistService $inat,
        protected SpeciesMerger $merger,
        protected TaxonService $taxonService,
    )
    {
    }

    public function explore(float $lat, float $lng, array $filters = []): array
    {
        $radius = (int) ($filters['radius'] ?? 50);
        $gbifFilters = $this->buildGbifFilters($filters);

        $cacheKey = $this->catalogCacheKey($lat, $lng, $radius, $gbifFilters);
        $catalogResult = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->gbif->searchSpeciesByRegion($lat, $lng, $radius, $gbifFilters)
        );

        return $this->formatCatalogResult($catalogResult, $filters, $radius, $lat, $lng);
    }

    public function exploreNational(array $filters = []): array
    {
        $gbifFilters = $this->buildGbifFilters($filters);
        $cacheFilters = $gbifFilters;
        ksort($cacheFilters);
        $cacheKey = 'explorer:country:v1:CO:' . sha1(json_encode($cacheFilters));
        $catalogResult = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->gbif->searchSpeciesByCountry('CO', $gbifFilters)
        );

        return $this->formatCatalogResult($catalogResult, $filters, null, null, null);
    }

    private function buildGbifFilters(array $filters): array
    {
        $query = trim((string) ($filters['q'] ?? ''));
        $localTaxonKey = null;

        if ($query !== '') {
            $localTaxonKey = Taxa::query()
                ->whereNotNull('gbif_taxon_key')
                ->where(function ($builder) use ($query) {
                    $builder->where('scientific_name', $query)
                        ->orWhere('common_name', $query);
                })
                ->value('gbif_taxon_key');
        }

        return array_filter([
            'q' => $query !== '' ? $query : null,
            'taxon_key' => $localTaxonKey,
            'iconic_taxa' => $filters['iconic_taxa'] ?? null,
            'native' => !empty($filters['native']),
            'catalog_limit' => 2000,
        ], fn ($value) => $value !== null && $value !== '' && $value !== false);
    }

    private function formatCatalogResult(
        array $catalogResult,
        array $filters,
        ?int $radius,
        ?float $latitude = null,
        ?float $longitude = null,
    ): array
    {
        if (!($catalogResult['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $catalogResult['error'] ?? 'Error al consultar GBIF',
            ];
        }

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(50, max(10, (int) ($filters['per_page'] ?? 25)));
        $catalogData = $catalogResult['data'] ?? [];
        $buckets = collect($catalogData['buckets'] ?? []);
        $usesEnrichmentFilter = !empty($filters['endemic']) || !empty($filters['threatened']);

        if ($usesEnrichmentFilter && $buckets->isNotEmpty()) {
            $buckets = $this->applyEnrichmentFilters($buckets, $filters);
        }

        if (($filters['order_by'] ?? null) === 'random') {
            $seed = (string) ($filters['random_seed'] ?? now()->format('Y-m-d-H'));
            $buckets = $buckets
                ->sortBy(fn (array $bucket) => sha1($seed . ':' . $bucket['species_key']))
                ->values();
        }

        $total = $buckets->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $pageBuckets = $buckets->slice(($page - 1) * $perPage, $perPage)->values();
        $pageKeys = $pageBuckets->pluck('species_key')->map(fn ($key) => (string) $key)->all();
        $detailsByKey = $this->gbif->getSpeciesDetailsBatch($pageKeys);
        $colombianNamesByKey = $this->gbif->getColombianVernacularNamesBatch($pageKeys);

        [$keyToId, $toEnrich] = $this->persistPageTaxa($pageBuckets, $detailsByKey, $colombianNamesByKey);
        $taxaById = Taxa::with('apiReferences')
            ->whereIn('id', array_values($keyToId))
            ->get()
            ->keyBy('id');

        // GBIF entrega primero la cobertura geográfica, pero los datos de iNaturalist
        // pueden llegar después por cola. Enriquecemos una parte de la página visible
        // antes de responder para que las tarjetas no aparezcan vacías en el primer load.
        $syncNames = $taxaById
            ->filter(function (Taxa $taxon) {
                $inatRef = $taxon->apiReferences->firstWhere('api_source', 'inaturalist');
                $data = $inatRef?->data ?? [];

                return !$inatRef
                    || empty($data['default_photo']) && empty($data['wikipedia_summary']);
            })
            ->pluck('scientific_name')
            ->filter()
            ->unique()
            ->take(self::SYNCHRONOUS_ENRICHMENT_LIMIT)
            ->values();

        foreach ($syncNames as $scientificName) {
            try {
                app(EnrichSpeciesJob::class, ['canonicalName' => $scientificName])
                    ->handle($this->gbif, $this->inat, $this->merger);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        if ($syncNames->isNotEmpty()) {
            $taxaById = Taxa::with('apiReferences')
                ->whereIn('id', array_values($keyToId))
                ->get()
                ->keyBy('id');
        }

        $results = [];
        foreach ($pageBuckets as $bucket) {
            $speciesKey = (string) $bucket['species_key'];
            $taxonId = $keyToId[$speciesKey] ?? null;
            $taxon = $taxonId ? $taxaById->get($taxonId) : null;
            if (!$taxon) {
                continue;
            }

            $item = $taxon->enriched_data;
            $gbifDetails = $detailsByKey[$speciesKey] ?? [];
            $displayName = $gbifDetails['canonicalName']
                ?? $gbifDetails['species']
                ?? $gbifDetails['scientificName']
                ?? $item['scientific_name'];
            $item['scientific_name'] = SpeciesMerger::stripAuthorship($displayName);
            $item['occurrence_count'] = (int) ($bucket['occurrence_count'] ?? 0);
            if ($radius !== null) {
                $item['nearby_radius_km'] = $radius;
            }
            $results[] = $item;
        }

        $inatOnly = $radius !== null && $page === 1
            ? $this->findINaturalistOnlySpecies(
                $detailsByKey,
                $latitude,
                $longitude,
                $radius,
            )
            : [];

        if ($inatOnly) {
            $inatNames = collect($inatOnly)->pluck('name')->values();
            foreach ($inatOnly as $inatSpecies) {
                try {
                    $this->taxonService->enrichFromINaturalistId((string) $inatSpecies['id']);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            $inatTaxa = Taxa::with('apiReferences')
                ->whereIn('scientific_name', $inatNames->take(self::SYNCHRONOUS_ENRICHMENT_LIMIT)->all())
                ->get();

            foreach ($inatTaxa as $taxon) {
                $item = $taxon->enriched_data;
                $source = collect($inatOnly)->firstWhere('name', $taxon->scientific_name);
                $item['scientific_name'] = $taxon->scientific_name;
                $item['observation_count'] = (int) ($source['observation_count'] ?? 0);
                $item['source'] = 'inaturalist';
                $results[] = $item;
            }
        }

        foreach (array_unique($toEnrich) as $name) {
            EnrichSpeciesJob::dispatch($name)->onQueue('species-sync');
        }

        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = $total === 0 ? 0 : min($page * $perPage, $total);

        return [
            'success' => true,
            'data' => $results,
            'meta' => [
                'source' => 'gbif',
                'secondary_source' => $inatOnly ? 'inaturalist' : null,
                'inat_only_count' => count($inatOnly),
                'scope' => $radius === null ? 'national' : 'nearby',
                'cached' => (bool) ($catalogResult['cached'] ?? false),
                'radius_km' => $radius,
                'occurrence_total' => (int) ($catalogData['occurrence_total'] ?? 0),
                'catalog_truncated' => (bool) ($catalogData['truncated'] ?? false),
                'enriching_count' => count(array_unique($toEnrich)),
                'enrichment_filter_applied' => $usesEnrichmentFilter,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'from' => $from,
                    'to' => $to,
                ],
            ],
        ];
    }

    private function findINaturalistOnlySpecies(array $gbifDetails, ?float $latitude, ?float $longitude, int $radius): array
    {
        if ($latitude === null || $longitude === null) {
            return [];
        }

        $gbifNames = collect($gbifDetails)
            ->map(fn (array $details) => SpeciesMerger::stripAuthorship(
                $details['canonicalName'] ?? $details['species'] ?? $details['scientificName'] ?? ''
            ))
            ->filter()
            ->mapWithKeys(fn (string $name) => [mb_strtolower($name) => true]);
        $details = $this->inat->getNearbySpecies($latitude, $longitude, $radius, 100);

        if (!($details['success'] ?? false)) {
            return [];
        }

        return collect($details['data'] ?? [])
            ->filter(fn (array $taxon) => !empty($taxon['name']))
            ->reject(fn (array $taxon) => $gbifNames->has(
                mb_strtolower(SpeciesMerger::stripAuthorship($taxon['name']))
            ))
            ->map(fn (array $taxon) => [
                'id' => $taxon['id'],
                'name' => SpeciesMerger::stripAuthorship($taxon['name']),
                'common_name' => $taxon['common_name'] ?? null,
                'default_photo' => $taxon['default_photo'] ?? null,
            ])
            ->unique('name')
            ->take(8)
            ->values()
            ->all();
    }
    private function catalogCacheKey(float $lat, float $lng, int $radius, array $filters): string
    {
        ksort($filters);
        $cellLat = number_format(round($lat, 3), 3, '.', '');
        $cellLng = number_format(round($lng, 3), 3, '.', '');

        return 'explorer:species-facet:v4:' . sha1(json_encode([
            'lat' => $cellLat,
            'lng' => $cellLng,
            'radius' => $radius,
            'filters' => $filters,
        ]));
    }

    private function applyEnrichmentFilters(Collection $buckets, array $filters): Collection
    {
        $keys = $buckets->pluck('species_key')->map(fn ($key) => (string) $key)->all();
        $query = Taxa::query()->whereIn('gbif_taxon_key', $keys);

        if (!empty($filters['endemic'])) {
            $query->where('is_endemic', true);
        }

        if (!empty($filters['threatened'])) {
            $query->whereIn('conservation_status', self::THREATENED_STATUSES);
        }

        $eligible = $query->pluck('gbif_taxon_key')->map(fn ($key) => (string) $key)->flip();

        return $buckets
            ->filter(fn (array $bucket) => $eligible->has((string) $bucket['species_key']))
            ->values();
    }

    /**
     * @return array{0: array<string, int>, 1: array<int, string>}
     */
    private function persistPageTaxa(
        Collection $pageBuckets,
        array $detailsByKey,
        array $colombianNamesByKey = []
    ): array
    {
        $keyToId = [];
        $toEnrich = [];
        $pageKeys = $pageBuckets
            ->pluck('species_key')
            ->map(fn ($key) => (string) $key)
            ->all();
        $existingByKey = Taxa::query()
            ->whereIn('gbif_taxon_key', $pageKeys)
            ->get()
            ->keyBy(fn (Taxa $taxon) => (string) $taxon->gbif_taxon_key);

        foreach ($pageBuckets as $bucket) {
            $speciesKey = (string) $bucket['species_key'];
            $details = $detailsByKey[$speciesKey] ?? null;
            if (!$details) {
                continue;
            }

            $rawName = $details['canonicalName'] ?? $details['species'] ?? $details['scientificName'] ?? null;
            $scientificName = $rawName ? SpeciesMerger::stripAuthorship($rawName) : null;
            if (!$scientificName) {
                continue;
            }

            $taxon = $existingByKey->get($speciesKey)
                ?? Taxa::firstOrCreate(
                    ['scientific_name' => $scientificName],
                    ['sync_status' => 'pending']
                );

            $updates = ['gbif_taxon_key' => $speciesKey];
            if (!empty($colombianNamesByKey[$speciesKey])) {
                $updates['common_name'] = $colombianNamesByKey[$speciesKey];
            }
            foreach ([
                'kingdom' => 'kingdom',
                'phylum' => 'phylum',
                'class' => 'class',
                'order' => 'order_name',
                'family' => 'family',
                'genus' => 'genus',
                'species' => 'species',
            ] as $gbifField => $databaseField) {
                if (empty($taxon->{$databaseField}) && !empty($details[$gbifField])) {
                    $updates[$databaseField] = $details[$gbifField];
                }
            }

            $taxon->fill($updates);
            if ($taxon->isDirty()) {
                $taxon->timestamps = false;
                $taxon->save();
                $taxon->timestamps = true;
            }
            $existingByKey->put($speciesKey, $taxon);
            $keyToId[$speciesKey] = $taxon->id;

            if (in_array($taxon->sync_status, ['pending', 'failed'], true)) {
                $toEnrich[] = $scientificName;
            }
        }

        return [$keyToId, $toEnrich];
    }
}
