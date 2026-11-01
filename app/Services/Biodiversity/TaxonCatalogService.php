<?php

namespace App\Services\Biodiversity;

use App\Jobs\EnrichSpeciesJob;
use App\Models\Observation;
use App\Models\TaxonApiReference;
use App\Models\Taxa;
use App\Services\ExternalApi\GbifService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Lecturas del catálogo local de especies.
 *
 * Regla de rendimiento: las listas (búsqueda, relacionadas) solo leen la base
 * de datos. Si una especie está incompleta se encola su enriquecimiento en
 * `species-sync` en lugar de llamar a iNaturalist durante la petición.
 * Solo la ficha individual puede consultar iNaturalist en línea, y únicamente
 * cuando la especie todavía no tiene datos suficientes para mostrarse.
 */
class TaxonCatalogService
{
    public function __construct(
        private readonly TaxonSyncService $sync,
        private readonly GbifService $gbif,
    ) {}

    /**
     * Clave de GBIF de la especie, necesaria para el mapa de ocurrencias.
     * Si aún no la tenemos se busca una sola vez y queda guardada.
     */
    public function ensureGbifKey(Taxa $taxon): ?string
    {
        if ($taxon->gbif_taxon_key) {
            return (string) $taxon->gbif_taxon_key;
        }

        $match = $this->gbif->searchTaxon($taxon->scientific_name)['data'][0] ?? [];
        $isSpecies = in_array($match['matchType'] ?? 'NONE', ['EXACT', 'FUZZY'], true)
            && in_array($match['rank'] ?? null, ['SPECIES', 'SUBSPECIES'], true);
        $key = $isSpecies ? ($match['speciesKey'] ?? $match['usageKey'] ?? null) : null;

        if ($key) {
            $taxon->forceFill(['gbif_taxon_key' => (string) $key])->saveQuietly();
        }

        return $key ? (string) $key : null;
    }

    /**
     * Usa GBIF (Backbone Taxonomy) como fuente de la taxonomía: reino, filo,
     * clase, orden, familia y género. Se consulta una vez por especie y queda
     * guardada en `taxa`; si GBIF no trae un rango (p. ej. la clase de los peces
     * óseos), se conserva el que ya teníamos.
     */
    public function ensureGbifTaxonomy(Taxa $taxon): void
    {
        $key = $this->ensureGbifKey($taxon);
        if (! $key || Cache::has("taxonomy:gbif:{$taxon->id}")) {
            return;
        }

        $details = $this->gbif->getSpeciesDetails($key);
        if (! $details) {
            return;
        }

        foreach (['kingdom' => 'kingdom', 'phylum' => 'phylum', 'class' => 'class', 'order' => 'order_name', 'family' => 'family', 'genus' => 'genus'] as $gbifField => $column) {
            if (! empty($details[$gbifField])) {
                $taxon->{$column} = $details[$gbifField];
            }
        }

        if ($taxon->isDirty()) {
            $taxon->saveQuietly();
        }

        Cache::put("taxonomy:gbif:{$taxon->id}", true, now()->addDays(90));
    }

    /**
     * Busca por nombre científico o común en el catálogo local.
     */
    public function search(string $query, int $perPage = 15): array
    {
        $paginator = Taxa::with('apiReferences')
            ->where(fn ($builder) => $builder
                ->where('scientific_name', 'like', "%{$query}%")
                ->orWhere('common_name', 'like', "%{$query}%"))
            ->orderByDesc('observation_count')
            ->paginate($perPage);

        $this->queueEnrichment(collect($paginator->items()));

        return [
            'data' => collect($paginator->items())->map->enriched_data->all(),
            'pagination' => self::pagination($paginator),
        ];
    }

    /**
     * Devuelve la ficha de una especie. Si el registro local está incompleto
     * (o se pide `refresh`), lo completa con iNaturalist antes de responder.
     *
     * @return array{taxon: ?Taxa, source: string}
     */
    public function find(int $id, bool $forceRefresh = false): array
    {
        $taxon = Taxa::with('apiReferences')->find($id)
            ?? Taxa::with('apiReferences')
                ->whereHas('apiReferences', fn ($ref) => $ref
                    ->where('api_source', 'inaturalist')
                    ->where('external_id', (string) $id))
                ->first();

        if ($taxon && ! $forceRefresh && $this->isComplete($taxon)) {
            return ['taxon' => $taxon, 'source' => 'local'];
        }

        $externalId = $taxon
            ? ($taxon->apiReferences->firstWhere('api_source', 'inaturalist')?->external_id ?: $taxon->inat_taxon_id)
            : (string) $id;

        if (! $externalId) {
            return ['taxon' => $taxon, 'source' => 'local_incomplete'];
        }

        $refreshed = $this->sync->enrichFromINaturalistId((string) $externalId);

        if ($refreshed) {
            return ['taxon' => $refreshed, 'source' => 'inaturalist'];
        }

        return ['taxon' => $taxon, 'source' => $taxon ? 'local_fallback' : 'not_found'];
    }

    /**
     * Avistamientos públicos de la comunidad EcoIses para una especie.
     */
    public function observations(int $taxonId, int $perPage = 15): array
    {
        $paginator = Observation::with(['user:id,full_name,avatar', 'photos'])
            ->where('taxon_id', $taxonId)
            ->where('is_public', true)
            ->orderByDesc('observed_at')
            ->paginate($perPage);

        return [
            'data' => $paginator->items(),
            'pagination' => self::pagination($paginator),
        ];
    }

    /**
     * Hasta tres especies del mismo género y, si no alcanzan, de la misma familia.
     */
    public function related(int $taxonId, int $limit = 3): array
    {
        $base = Taxa::find($taxonId);
        if (! $base) {
            return [];
        }

        $related = collect();
        foreach (['genus', 'family'] as $rank) {
            if ($related->count() >= $limit || ! $base->{$rank}) {
                continue;
            }

            $related = $related->concat(
                Taxa::with('apiReferences')
                    ->where($rank, $base->{$rank})
                    ->whereKeyNot($base->id)
                    ->whereNotIn('id', $related->pluck('id'))
                    ->orderByDesc('observation_count')
                    ->limit($limit - $related->count())
                    ->get()
            );
        }

        return $related->map->enriched_data->values()->all();
    }

    /**
     * Una ficha está completa si tiene taxonomía y al menos foto o resumen.
     */
    public function isComplete(Taxa $taxon): bool
    {
        $data = $taxon->apiReferences->firstWhere('api_source', 'inaturalist')?->data ?? [];

        $hasTaxonomy = ! empty($data['ancestors']) || ($taxon->kingdom && $taxon->family && $taxon->genus);
        $hasContent = ! empty($data['gallery']) || ! empty($data['default_photo']) || ! empty($data['wikipedia_summary']);

        return $hasTaxonomy && $hasContent;
    }

    /**
     * La especie tiene foto principal pero no galería, y no se ha revisado en
     * iNaturalist en el último mes (algunas de verdad no tienen más fotos).
     */
    public function needsGallery(Taxa $taxon): bool
    {
        $reference = $taxon->apiReferences->firstWhere('api_source', 'inaturalist');
        $data = $reference?->data ?? [];

        if (! $reference || ! empty($data['gallery']) || ! ($reference->external_id ?: $taxon->inat_taxon_id)) {
            return false;
        }

        $checkedAt = $data['gallery_checked_at'] ?? null;

        return ! $checkedAt || now()->subMonth()->gt($checkedAt);
    }

    /**
     * Pide la ficha completa a iNaturalist (trae la galería) y anota la revisión,
     * haya o no fotos nuevas, para no volver a pedirla en un mes.
     */
    public function fetchGallery(Taxa $taxon): void
    {
        $reference = $taxon->apiReferences->firstWhere('api_source', 'inaturalist');
        $externalId = (string) ($reference?->external_id ?: $taxon->inat_taxon_id);

        $this->sync->enrichFromINaturalistId($externalId);

        $fresh = TaxonApiReference::where('taxon_id', $taxon->id)->where('api_source', 'inaturalist')->first();
        if ($fresh) {
            $fresh->update(['data' => array_merge($fresh->data ?? [], ['gallery_checked_at' => now()->toIso8601String()])]);
        }
    }

    private function queueEnrichment(Collection $taxa): void
    {
        $taxa->reject(fn (Taxa $taxon) => $taxon->sync_status === 'synced' || $this->isComplete($taxon))
            ->each(fn (Taxa $taxon) => EnrichSpeciesJob::dispatch($taxon->scientific_name));
    }

    public static function pagination($paginator): array
    {
        return [
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
