<?php

namespace App\Services\Biodiversity;

use App\Models\ColombiaSpeciesList;
use App\Models\Taxa;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Etiquetas de conservación y origen a partir de fuentes oficiales, sin token:
 *
 *  - Amenaza en Colombia: Resolución 0126 de 2024 de MinAmbiente (checklist en GBIF).
 *  - Amenaza global: Lista Roja de la UICN, expuesta por GBIF por especie.
 *  - Endémicas: aves endémicas y casi endémicas del Instituto Humboldt; para otros
 *    grupos, la bandera "endémica" de iNaturalist para Colombia.
 *  - Introducidas: GRIIS Colombia (Global Register of Introduced and Invasive Species).
 *  - Nativas: no hay lista oficial completa; se usa iNaturalist solo cuando lo indica.
 *
 * Las listas se descargan una vez (comando checklists:sync) y todo se lee de la
 * base de datos, así la ficha y el explorador muestran siempre la misma etiqueta.
 */
class ConservationLabelService
{
    private const GBIF = 'https://api.gbif.org/v1';

    /** Dataset de GBIF de cada lista. */
    public const LISTS = [
        ColombiaSpeciesList::THREAT => '09174029-d182-442c-a12f-8013aee328d7',
        ColombiaSpeciesList::ENDEMIC_BIRDS => 'b08f433a-c35b-4e6d-8400-df5b84c3d379',
        ColombiaSpeciesList::INTRODUCED => '168568e7-eb5f-4ef6-8c59-f73ceaf57e91',
    ];

    private const THREAT_CODES = [
        'EXTINCT' => 'EX', 'EXTINCT_IN_THE_WILD' => 'EW', 'CRITICALLY_ENDANGERED' => 'CR', 'ENDANGERED' => 'EN',
        'VULNERABLE' => 'VU', 'NEAR_THREATENED' => 'NT', 'LEAST_CONCERN' => 'LC', 'DATA_DEFICIENT' => 'DD',
    ];

    public const CATEGORY_LABELS = [
        'EX' => 'Extinta', 'EW' => 'Extinta en estado silvestre', 'CR' => 'En peligro crítico', 'EN' => 'En peligro',
        'VU' => 'Vulnerable', 'NT' => 'Casi amenazada', 'LC' => 'Preocupación menor', 'DD' => 'Datos insuficientes',
    ];

    /**
     * Etiquetas de una especie.
     *
     * @return array{national: ?array, global: ?array, origin: ?array}
     */
    public function labelsFor(Taxa $taxon): array
    {
        return $this->labelsForMany(collect([$taxon]))[$taxon->id];
    }

    /**
     * Etiquetas de varias especies con una sola consulta a las listas.
     *
     * @param  Collection<int, Taxa>  $taxa
     * @return array<int, array{national: ?array, global: ?array, origin: ?array}>
     */
    public function labelsForMany(Collection $taxa): array
    {
        $keys = $taxa->pluck('gbif_taxon_key')->filter()->map(fn ($key) => (string) $key)->values();
        $names = $taxa->pluck('scientific_name')->filter()->values();

        $rows = ColombiaSpeciesList::query()
            ->where(fn ($query) => $query->whereIn('gbif_taxon_key', $keys)->orWhereIn('scientific_name', $names))
            ->get();

        return $taxa->mapWithKeys(function (Taxa $taxon) use ($rows) {
            $mine = $rows->filter(fn (ColombiaSpeciesList $row) => ($taxon->gbif_taxon_key && $row->gbif_taxon_key === (string) $taxon->gbif_taxon_key)
                || strcasecmp($row->scientific_name, $taxon->scientific_name) === 0);

            return [$taxon->id => [
                'national' => $this->national($mine->firstWhere('list', ColombiaSpeciesList::THREAT)),
                'global' => $this->global($taxon),
                'origin' => $this->origin($taxon, $mine),
            ]];
        })->all();
    }

    private function national(?ColombiaSpeciesList $row): ?array
    {
        if (! $row?->category) {
            return null;
        }

        return [
            'code' => $row->category,
            'label' => self::CATEGORY_LABELS[$row->category] ?? $row->category,
            'scope' => 'Colombia',
            'source' => 'MinAmbiente · Resolución 0126 de 2024',
        ];
    }

    private function global(Taxa $taxon): ?array
    {
        $code = $taxon->iucn_category;
        if (! $code || $code === 'NE') {
            return null;
        }

        return [
            'code' => $code,
            'label' => self::CATEGORY_LABELS[$code] ?? $code,
            'scope' => 'Global',
            'source' => 'Lista Roja de la UICN',
            'url' => $taxon->iucn_taxon_id
                ? "https://www.iucnredlist.org/species/{$taxon->iucn_taxon_id}/0"
                : 'https://www.iucnredlist.org/search?query='.rawurlencode($taxon->scientific_name),
        ];
    }

    private function origin(Taxa $taxon, Collection $rows): ?array
    {
        if ($rows->firstWhere('list', ColombiaSpeciesList::INTRODUCED)) {
            return ['status' => 'introduced', 'label' => 'Introducida', 'source' => 'GRIIS Colombia'];
        }

        $bird = $rows->firstWhere('list', ColombiaSpeciesList::ENDEMIC_BIRDS);
        if ($bird?->category === 'endemic') {
            return ['status' => 'endemic', 'label' => 'Endémica de Colombia', 'source' => 'Instituto Humboldt'];
        }
        if ($bird?->category === 'near_endemic') {
            return ['status' => 'near_endemic', 'label' => 'Casi endémica', 'source' => 'Instituto Humboldt'];
        }

        if ($taxon->is_endemic) {
            return ['status' => 'endemic', 'label' => 'Endémica de Colombia', 'source' => 'iNaturalist'];
        }
        if ($taxon->is_introduced) {
            return ['status' => 'introduced', 'label' => 'Introducida', 'source' => 'iNaturalist'];
        }
        if ($taxon->is_native) {
            return ['status' => 'native', 'label' => 'Nativa', 'source' => 'iNaturalist'];
        }

        return null;
    }

    /**
     * Descarga de nuevo las listas oficiales desde GBIF.
     *
     * @return array<string, int> registros por lista
     */
    public function syncLists(): array
    {
        $counts = [];

        foreach (self::LISTS as $list => $datasetKey) {
            $records = $this->datasetRecords($datasetKey);
            $categories = $list === ColombiaSpeciesList::ENDEMIC_BIRDS ? $this->endemismCategories($records) : [];
            $now = now();

            $rows = collect($records)
                ->filter(fn (array $record) => ! empty($record['canonicalName']) && str_contains($record['canonicalName'], ' '))
                ->map(fn (array $record) => [
                    'list' => $list,
                    'gbif_taxon_key' => isset($record['nubKey']) ? (string) $record['nubKey'] : null,
                    'scientific_name' => $record['canonicalName'],
                    'category' => match ($list) {
                        ColombiaSpeciesList::THREAT => self::THREAT_CODES[$record['threatStatuses'][0] ?? ''] ?? null,
                        ColombiaSpeciesList::ENDEMIC_BIRDS => $categories[$record['key']] ?? null,
                        default => 'introduced',
                    },
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->unique('scientific_name')
                ->values();

            if ($rows->isEmpty()) {
                continue; // si GBIF no respondió, se conserva la versión anterior
            }

            DB::transaction(function () use ($list, $rows) {
                ColombiaSpeciesList::where('list', $list)->delete();
                $rows->chunk(500)->each(fn ($chunk) => ColombiaSpeciesList::insert($chunk->all()));
            });
            $counts[$list] = $rows->count();
        }

        return $counts;
    }

    /**
     * Categoría global de la UICN desde GBIF, en paralelo por tandas.
     *
     * @param  Collection<int, Taxa>  $taxa
     */
    public function syncIucn(Collection $taxa): int
    {
        $updated = 0;

        foreach ($taxa->filter(fn (Taxa $taxon) => $taxon->gbif_taxon_key)->chunk(25) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => $chunk->map(
                fn (Taxa $taxon) => $pool->as((string) $taxon->id)->timeout(20)->get(self::GBIF."/species/{$taxon->gbif_taxon_key}/iucnRedListCategory")
            )->all());

            foreach ($chunk as $taxon) {
                $response = $responses[(string) $taxon->id] ?? null;
                if (! $response instanceof Response) {
                    continue;
                }

                $data = $response->successful() ? $response->json() : [];
                $taxon->forceFill([
                    'iucn_category' => $data['code'] ?? null,
                    'iucn_taxon_id' => $data['iucnTaxonID'] ?? null,
                    'iucn_synced_at' => now(),
                ])->saveQuietly();
                $updated++;
            }
        }

        return $updated;
    }

    /** Todos los registros de un checklist de GBIF (páginas de 300, con reintentos). */
    private function datasetRecords(string $datasetKey): array
    {
        $records = [];
        for ($offset = 0; $offset < 20000; $offset += 300) {
            try {
                $response = Http::timeout(120)->retry(3, 2000)
                    ->get(self::GBIF.'/species/search', ['datasetKey' => $datasetKey, 'limit' => 300, 'offset' => $offset]);
            } catch (\Throwable $e) {
                report($e);

                return []; // lista incompleta: se conserva la versión anterior
            }
            if (! $response->successful()) {
                return [];
            }

            $records = array_merge($records, $response->json('results') ?? []);
            if ($response->json('endOfRecords')) {
                break;
            }
        }

        return $records;
    }

    /**
     * La lista de aves del Humboldt guarda "Endémica" o "Casi endémica" en la
     * descripción de tipo "Distribución" de cada registro.
     *
     * @return array<int, string> clave del registro => endemic | near_endemic
     */
    private function endemismCategories(array $records): array
    {
        $categories = [];

        foreach (array_chunk($records, 25) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (array $record) => $pool->as((string) $record['key'])->timeout(20)->get(self::GBIF."/species/{$record['key']}/descriptions"),
                $chunk
            ));

            foreach ($responses as $key => $response) {
                if (! $response instanceof Response || ! $response->successful()) {
                    continue;
                }

                $text = collect($response->json('results') ?? [])->firstWhere('type', 'Distribución')['description'] ?? '';
                $text = mb_strtolower(trim($text));
                $categories[(int) $key] = match (true) {
                    str_starts_with($text, 'casi') => 'near_endemic',
                    str_starts_with($text, 'end') => 'endemic',
                    default => null,
                };
            }
        }

        return array_filter($categories);
    }
}
