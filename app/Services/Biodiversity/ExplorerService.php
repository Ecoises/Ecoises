<?php

namespace App\Services\Biodiversity;

use App\Jobs\EnrichSpeciesJob;
use App\Models\Taxa;
use App\Services\ExternalApi\GbifService;
use App\Services\ExternalApi\INaturalistService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Explorador de especies de Colombia.
 *
 * Combina dos fuentes con papeles distintos:
 *
 *  1. citizenScience()    → iNaturalist (/observations/species_counts). Una sola
 *     llamada trae especies con foto y nombre común en español, ya filtradas por
 *     zona, grupo, nativas, endémicas y amenazadas. Es la lista que se ve primero.
 *
 *  2. scientificRecords() → GBIF. Todas las especies con registros en GBIF para
 *     la zona (colecciones, museos, monitoreos y también las que GBIF recibe de
 *     iNaturalist). Se calcula una vez por zona y filtro, se guarda en caché una
 *     semana y se pide aparte, así nunca retrasa la lista principal.
 *
 * Las especies que aparecen se guardan en `taxa` para que su ficha abra al instante.
 */
class ExplorerService
{
    public const GROUPS = ['Aves', 'Mammalia', 'Reptilia', 'Amphibia', 'Actinopterygii', 'Insecta', 'Arachnida', 'Mollusca', 'Plantae', 'Fungi'];

    private const LIST_TTL = 86400;            // 24 h

    private const SCIENTIFIC_TTL = 604800;     // 7 días

    private const SCIENTIFIC_CANDIDATES = 10000; // máximo de especies de GBIF por zona

    private const THREATENED_STATUSES = ['VU', 'EN', 'CR'];

    public function __construct(
        protected GbifService $gbif,
        protected INaturalistService $inat,
        protected ConservationLabelService $labels,
    ) {}

    /**
     * Lista principal (iNaturalist), paginada.
     *
     * @param  array{lat?: ?float, lng?: ?float, radius?: int, group?: ?string, q?: ?string, native?: bool, endemic?: bool, threatened?: bool, order?: string, seed?: ?string, page?: int, per_page?: int}  $filters
     */
    public function citizenScience(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 24);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $params = $this->inatParams($filters);

        if ($params === null) {
            return $this->emptyPage($perPage);
        }

        $isRandom = ($filters['order'] ?? 'popular') === 'random';
        $requestedPage = $isRandom ? $this->randomPage($params, $perPage, $page, (string) ($filters['seed'] ?? 'ecoises')) : $page;

        $response = $this->speciesCounts($params, $requestedPage, $perPage);
        if (! ($response['success'] ?? false)) {
            return ['success' => false, 'error' => $response['error'] ?? 'iNaturalist no respondió'];
        }

        $species = collect($response['data']);
        if ($isRandom) {
            $species = $this->seededShuffle($species, ($filters['seed'] ?? 'ecoises').":{$page}");
        }

        $idsByName = $this->persistINaturalistTaxa($species);
        $total = (int) ($response['pagination']['total'] ?? $species->count());

        return [
            'success' => true,
            'data' => $this->applyOfficialLabels(
                $species
                    ->map(fn (array $taxon) => $this->inatCard($taxon, $idsByName, $filters))
                    ->filter(fn ($card) => $card['id'])
                    ->values()
                    ->all()
            ),
            'meta' => [
                'source' => 'inaturalist',
                'scope' => isset($params['lat']) ? 'nearby' : 'national',
                'pagination' => $this->pagination($total, $perPage, $page),
            ],
        ];
    }

    /**
     * Todas las especies con registros en GBIF para la zona y filtros. Si es la primera vez que alguien pide esa combinación, se
     * calcula en esta petición (unos segundos) y queda en caché una semana.
     */
    public function scientificRecords(array $filters): array
    {
        $perPage = (int) ($filters['per_page'] ?? 24);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $key = 'explorer:scientific:v5:'.sha1(json_encode($this->scopeSignature($filters)));

        $entries = Cache::get($key);
        if ($entries === null) {
            $lock = Cache::lock($key.':lock', 180);
            if (! $lock->get()) {
                return ['success' => true, 'status' => 'building', 'data' => [], 'meta' => ['pagination' => $this->pagination(0, $perPage, 1)]];
            }

            try {
                $entries = $this->buildScientificRecords($filters);
                Cache::put($key, $entries, self::SCIENTIFIC_TTL);
            } finally {
                $lock->release();
            }
        }

        $total = count($entries);
        $slice = collect(array_slice($entries, ($page - 1) * $perPage, $perPage));
        $details = $this->gbif->getSpeciesDetailsBatch($slice->pluck('species_key')->all());
        $taxaByKey = $this->persistGbifTaxa($slice, $details);
        $taxa = Taxa::with('apiReferences')->whereIn('id', $taxaByKey->pluck('id'))->get()->keyBy('id');

        $cards = $slice
            ->map(function (array $entry) use ($taxa, $taxaByKey) {
                $taxon = $taxa->get($taxaByKey->get((string) $entry['species_key'])?->id);

                return $taxon ? $this->localCard($taxon, 'gbif', ['occurrence_count' => $entry['occurrence_count']]) : null;
            })
            ->filter()
            ->values();

        // Las fotos que aún faltan se buscan en segundo plano.
        $cards->whereNull('default_photo')->each(fn (array $card) => EnrichSpeciesJob::dispatch($card['scientific_name']));

        return [
            'success' => true,
            'status' => 'ready',
            'data' => $this->applyOfficialLabels($cards->all()),
            'meta' => ['source' => 'gbif', 'pagination' => $this->pagination($total, $perPage, $page)],
        ];
    }

    /**
     * Especies con registros en GBIF para la zona, de la más a la menos registrada.
     *
     * @return array<int, array{species_key: string, occurrence_count: int}>
     */
    private function buildScientificRecords(array $filters): array
    {
        $gbifFilters = array_filter([
            'iconic_taxa' => $filters['group'] ?? (empty($filters['q']) ? 'Biota' : null),
            'catalog_limit' => self::SCIENTIFIC_CANDIDATES,
            'q' => $filters['q'] ?? null,
        ]);

        $all = $this->gbifCatalog($filters, $gbifFilters);

        $entries = collect($all['data']['buckets'] ?? [])
            ->map(fn (array $bucket) => ['species_key' => (string) $bucket['species_key'], 'occurrence_count' => (int) $bucket['occurrence_count']])
            ->values();

        // Nativas, endémicas y amenazadas: GBIF casi nunca trae ese dato, así que
        // se usan las banderas del catálogo local para las especies que ya conocemos.
        if (! empty($filters['native']) || ! empty($filters['endemic']) || ! empty($filters['threatened'])) {
            $known = Taxa::whereIn('gbif_taxon_key', $entries->pluck('species_key'))->get()
                ->filter(fn (Taxa $taxon) => $this->passesLocalFlags($taxon, $filters))
                ->pluck('gbif_taxon_key')->map(fn ($key) => (string) $key)->flip();
            $entries = $entries->filter(fn (array $entry) => $known->has($entry['species_key']))->values();
        }

        return $entries->all();
    }

    private function gbifCatalog(array $filters, array $gbifFilters): array
    {
        return isset($filters['lat'], $filters['lng'])
            ? $this->gbif->searchSpeciesByRegion((float) $filters['lat'], (float) $filters['lng'], (int) ($filters['radius'] ?? 25), $gbifFilters)
            : $this->gbif->searchSpeciesByCountry('CO', $gbifFilters);
    }

    /**
     * Nativas, endémicas y amenazadas no se pueden filtrar bien en GBIF: se usan las banderas
     * del catálogo local, que vienen de iNaturalist con su fuente.
     */
    private function passesLocalFlags(Taxa $taxon, array $filters): bool
    {
        if (! empty($filters['native']) && ! $taxon->is_native) {
            return false;
        }

        if (! empty($filters['endemic']) && ! $taxon->is_endemic) {
            return false;
        }

        return empty($filters['threatened']) || in_array($taxon->conservation_status, self::THREATENED_STATUSES, true);
    }

    /** Parámetros de species_counts. Devuelve null si la búsqueda no encontró ningún taxón. */
    private function inatParams(array $filters): ?array
    {
        $params = [];

        if (isset($filters['lat'], $filters['lng'])) {
            $params += ['lat' => round((float) $filters['lat'], 3), 'lng' => round((float) $filters['lng'], 3), 'radius' => (int) ($filters['radius'] ?? 25), 'place_id' => null];
        }

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $taxonId = Cache::remember('explorer:inat-taxon:'.mb_strtolower($query), self::SCIENTIFIC_TTL, fn () => $this->inat->autocompleteTaxonId($query) ?? 0);
            if (! $taxonId) {
                return null;
            }
            $params['taxon_id'] = $taxonId;
        } elseif (! empty($filters['group'])) {
            $params['iconic_taxa'] = $filters['group'];
        }

        foreach (['native', 'endemic', 'threatened'] as $flag) {
            if (! empty($filters[$flag])) {
                $params[$flag] = 'true';
            }
        }

        return $params;
    }

    private function speciesCounts(array $params, int $page, int $perPage): array
    {
        $key = 'explorer:inat:v1:'.sha1(json_encode([$params, $page, $perPage]));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached;
        }

        $response = $this->inat->getSpeciesCounts($params + ['page' => $page, 'per_page' => $perPage]);
        if ($response['success'] ?? false) {
            Cache::put($key, $response, self::LIST_TTL);
        }

        return $response;
    }

    /**
     * Orden aleatorio estable: con la misma semilla, la página 2 siempre es la misma.
     * Se baraja el orden de las páginas de iNaturalist y luego cada página.
     */
    private function randomPage(array $params, int $perPage, int $page, string $seed): int
    {
        $first = $this->speciesCounts($params, 1, $perPage);
        $lastPage = max(1, (int) ceil(((int) ($first['pagination']['total'] ?? 0)) / $perPage));
        // iNaturalist no deja paginar más allá de 10.000 resultados.
        $lastPage = min($lastPage, (int) floor(10000 / $perPage));

        $pages = range(1, $lastPage);
        mt_srand(crc32($seed));
        shuffle($pages);
        mt_srand();

        return $pages[($page - 1) % $lastPage];
    }

    private function seededShuffle(Collection $items, string $seed): Collection
    {
        return $items->sortBy(fn (array $item) => sha1($seed.':'.($item['id'] ?? '')))->values();
    }

    /**
     * Crea en `taxa` las especies de iNaturalist que aún no existen (una sola
     * inserción) y devuelve sus IDs locales por nombre científico.
     *
     * @return array<string, int>
     */
    private function persistINaturalistTaxa(Collection $species): array
    {
        $names = $species->pluck('scientific_name')->filter()->unique()->values();
        $existing = Taxa::whereIn('scientific_name', $names)->pluck('id', 'scientific_name');

        $now = now();
        $missing = $species
            ->reject(fn (array $taxon) => empty($taxon['scientific_name']) || $existing->has($taxon['scientific_name']))
            ->unique('scientific_name')
            ->map(fn (array $taxon) => [
                'scientific_name' => $taxon['scientific_name'],
                'common_name' => $taxon['common_name'],
                'inat_taxon_id' => $taxon['id'],
                'is_native' => $taxon['native'] || $taxon['endemic'],
                'is_endemic' => $taxon['endemic'],
                'is_introduced' => $taxon['introduced'],
                'sync_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        if ($missing->isNotEmpty()) {
            Taxa::insertOrIgnore($missing->values()->all());
            $existing = Taxa::whereIn('scientific_name', $names)->pluck('id', 'scientific_name');
        }

        return $existing->all();
    }

    /**
     * Crea o completa en `taxa` las especies de GBIF (taxonomía y nombre común).
     *
     * @return Collection<string, Taxa> indexado por species_key de GBIF
     */
    private function persistGbifTaxa(Collection $buckets, array $details): Collection
    {
        $keys = $buckets->pluck('species_key')->map(fn ($key) => (string) $key)->all();
        $byKey = Taxa::whereIn('gbif_taxon_key', $keys)->get()->keyBy(fn (Taxa $taxon) => (string) $taxon->gbif_taxon_key);

        foreach ($keys as $key) {
            if ($byKey->has($key) || empty($details[$key])) {
                continue;
            }

            $detail = $details[$key];
            $name = SpeciesMerger::stripAuthorship($detail['canonicalName'] ?? $detail['species'] ?? $detail['scientificName'] ?? '');
            if ($name === '') {
                continue;
            }

            $taxon = Taxa::firstOrCreate(['scientific_name' => $name], ['sync_status' => 'pending']);
            $taxon->fill(array_filter([
                'gbif_taxon_key' => $key,
                'kingdom' => $taxon->kingdom ?: ($detail['kingdom'] ?? null),
                'phylum' => $taxon->phylum ?: ($detail['phylum'] ?? null),
                'class' => $taxon->class ?: ($detail['class'] ?? null),
                'order_name' => $taxon->order_name ?: ($detail['order'] ?? null),
                'family' => $taxon->family ?: ($detail['family'] ?? null),
                'genus' => $taxon->genus ?: ($detail['genus'] ?? null),
            ]));
            if ($taxon->isDirty()) {
                $taxon->save();
            }
            $byKey->put($key, $taxon);
        }

        return $byKey;
    }

    private function inatCard(array $taxon, array $idsByName, array $filters = []): array
    {
        $photo = $taxon['default_photo'] ?? null;
        // species_counts no siempre trae el estatus en Colombia; si la lista se
        // pidió filtrada por endémicas o nativas, iNaturalist ya lo garantizó.
        $endemic = $taxon['endemic'] || ! empty($filters['endemic']);
        $native = $taxon['native'] || $endemic || ! empty($filters['native']);

        return [
            'id' => $idsByName[$taxon['scientific_name']] ?? null,
            'scientific_name' => $taxon['scientific_name'],
            'common_name' => $taxon['common_name'],
            'default_photo' => $photo ? [
                'url' => $photo['medium_url'] ?? $photo['url'] ?? null,
                'attribution' => $photo['attribution'] ?? null,
            ] : null,
            'is_native' => $native,
            'is_endemic' => $endemic,
            'is_introduced' => $taxon['introduced'] && ! $native,
            'is_threatened' => ($taxon['threatened'] ?? false) || ! empty($filters['threatened']),
            'conservation_status' => $taxon['conservation_status']['status'] ?? null,
            'group' => $taxon['iconic_taxon_name'] ?? null,
            'observation_count' => (int) ($taxon['observations_count'] ?? 0),
            'source' => 'inaturalist',
        ];
    }

    private function localCard(Taxa $taxon, string $source, array $extra = []): array
    {
        $data = $taxon->enriched_data;
        $photo = $data['default_photo'] ?? null;
        $url = is_array($photo) ? ($photo['medium_url'] ?? $photo['url'] ?? null) : null;

        return array_merge([
            'id' => $taxon->id,
            'scientific_name' => $taxon->scientific_name,
            'common_name' => $taxon->common_name,
            'default_photo' => $url ? ['url' => $url, 'attribution' => $photo['attribution'] ?? null] : null,
            'is_native' => (bool) ($data['is_native'] ?? false),
            'is_endemic' => (bool) ($data['is_endemic'] ?? false),
            'is_introduced' => (bool) ($data['is_introduced'] ?? false),
            'is_threatened' => in_array($taxon->conservation_status, self::THREATENED_STATUSES, true),
            'conservation_status' => $taxon->conservation_status,
            'group' => $taxon->class ?: $taxon->kingdom,
            'source' => $source,
        ], $extra);
    }

    /**
     * Sustituye el estatus de las tarjetas por las etiquetas oficiales
     * (Res. 0126/2024, UICN, Humboldt, GRIIS) para que coincidan con la ficha.
     */
    private function applyOfficialLabels(array $cards): array
    {
        $taxa = Taxa::whereIn('id', array_column($cards, 'id'))
            ->get(['id', 'scientific_name', 'gbif_taxon_key', 'iucn_category', 'iucn_taxon_id', 'is_native', 'is_endemic', 'is_introduced']);
        $labels = $this->labels->labelsForMany($taxa);

        return array_map(function (array $card) use ($labels) {
            $label = $labels[$card['id']] ?? null;
            if (! $label) {
                return $card;
            }

            $conservation = $label['national'] ?? $label['global'];
            if ($conservation) {
                $card['conservation_status'] = $conservation['code'];
                $card['conservation_scope'] = $conservation['scope'];
                $card['is_threatened'] = in_array($conservation['code'], self::THREATENED_STATUSES, true);
            }

            if ($origin = $label['origin']) {
                $card['is_endemic'] = $origin['status'] === 'endemic';
                $card['is_near_endemic'] = $origin['status'] === 'near_endemic';
                $card['is_introduced'] = $origin['status'] === 'introduced';
                $card['is_native'] = in_array($origin['status'], ['native', 'endemic', 'near_endemic'], true);
            }

            return $card;
        }, $cards);
    }

    /** Lo que define una zona + filtro para la caché de registros científicos (celdas de ~25 km). */
    private function scopeSignature(array $filters): array
    {
        return [
            'cell' => isset($filters['lat'], $filters['lng'])
                ? [round((float) $filters['lat'] * 4) / 4, round((float) $filters['lng'] * 4) / 4, (int) ($filters['radius'] ?? 25)]
                : 'CO',
            'group' => $filters['group'] ?? null,
            'q' => mb_strtolower(trim((string) ($filters['q'] ?? ''))),
            'native' => ! empty($filters['native']),
            'endemic' => ! empty($filters['endemic']),
            'threatened' => ! empty($filters['threatened']),
        ];
    }

    private function pagination(int $total, int $perPage, int $page): array
    {
        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
        ];
    }

    private function emptyPage(int $perPage): array
    {
        return ['success' => true, 'data' => [], 'meta' => ['source' => 'inaturalist', 'pagination' => $this->pagination(0, $perPage, 1)]];
    }
}
