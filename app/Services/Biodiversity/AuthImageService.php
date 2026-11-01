<?php

namespace App\Services\Biodiversity;

use App\Models\Taxa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fotos de las pantallas de acceso: la foto oficial de cada especie en
 * iNaturalist (la que representa al taxón), de especies conocidas de todo el
 * mundo y de grandes grupos variados (aves, mamíferos, hongos…), en resolución
 * original y solo con licencia Creative Commons para mostrarla con crédito.
 *
 * iNaturalist tarda varios segundos en responder, así que la pantalla nunca lo
 * espera: lee lo que ya está en caché y los grupos que faltan se llenan en
 * segundo plano (WarmAuthImagesJob). Mientras tanto usa una especie local.
 */
class AuthImageService
{
    private const ENDPOINT = 'https://api.inaturalist.org/v1/taxa';

    /** Taxón de iNaturalist de cada grupo. */
    private const TAXON_IDS = [
        'Aves' => 3,
        'Mammalia' => 40151,
        'Reptilia' => 26036,
        'Amphibia' => 20978,
        'Actinopterygii' => 47178,
        'Insecta' => 47158,
        'Arachnida' => 47119,
        'Mollusca' => 47115,
        'Fungi' => 47170,
        'Plantae' => 47126,
    ];

    /** Grupo de iNaturalist => nombre en la pantalla. */
    public const GROUPS = [
        'Aves' => 'Ave',
        'Mammalia' => 'Mamífero',
        'Reptilia' => 'Reptil',
        'Amphibia' => 'Anfibio',
        'Actinopterygii' => 'Pez',
        'Insecta' => 'Insecto',
        'Arachnida' => 'Arácnido',
        'Mollusca' => 'Molusco',
        'Fungi' => 'Hongo',
        'Plantae' => 'Planta',
    ];

    /** Lado mayor mínimo de la foto original para que no se vea pixelada a media pantalla. */
    private const MIN_SIDE = 1600;

    /** Una foto al azar de un grupo al azar entre los ya guardados; si no hay, una especie local. */
    public function random(): ?array
    {
        $ready = collect(array_keys(self::GROUPS))
            ->mapWithKeys(fn (string $group) => [$group => Cache::get($this->key($group))])
            ->filter(fn ($pool) => is_array($pool) && $pool !== []);

        if ($ready->isEmpty()) {
            return $this->localFallback();
        }

        $group = $ready->keys()->random();
        $pool = $ready[$group];

        return $pool[array_rand($pool)] + ['group' => self::GROUPS[$group]];
    }

    /** Grupos que aún no tienen fotos guardadas. */
    public function missingGroups(): array
    {
        return array_values(array_filter(array_keys(self::GROUPS), fn (string $group) => ! Cache::has($this->key($group))));
    }

    /**
     * Guarda una semana la foto oficial de 50 especies del grupo, tomadas de
     * una página al azar entre las 500 más observadas (conocidas, pero variadas).
     */
    public function warm(string $group): int
    {
        try {
            $response = Http::timeout(30)->get(self::ENDPOINT, [
                'taxon_id' => self::TAXON_IDS[$group],
                'rank' => 'species',
                'is_active' => 'true',
                'order_by' => 'observations_count',
                'per_page' => 50,
                'page' => random_int(1, 10),
                'locale' => 'es',
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron traer las fotos del acceso', ['group' => $group, 'message' => $e->getMessage()]);

            return 0;
        }

        $pool = collect($response->successful() ? $response->json('results', []) : [])
            ->map(function (array $taxon) {
                $photo = $taxon['default_photo'] ?? null;
                $size = $photo['original_dimensions'] ?? [];
                $medium = (string) ($photo['medium_url'] ?? '');
                if (! $photo || ! $medium || empty($photo['license_code'])
                    || max($size['width'] ?? 0, $size['height'] ?? 0) < self::MIN_SIDE) {
                    return null;
                }

                return [
                    'name' => $taxon['preferred_common_name'] ?? $taxon['name'] ?? '',
                    'scientificName' => $taxon['name'] ?? '',
                    'image' => str_replace('/medium.', '/original.', $medium),
                    'preview' => $medium,
                    'author' => $photo['attribution'] ?? '',
                ];
            })
            ->filter()
            ->values()
            ->all();

        // Si iNaturalist no respondió bien no se guarda, para reintentar después.
        if ($pool !== []) {
            Cache::put($this->key($group), $pool, now()->addWeek());
        }

        return count($pool);
    }

    /** Especie local con foto de iNaturalist, pedida en su tamaño original. */
    private function localFallback(): ?array
    {
        $taxon = Taxa::with('apiReferences')
            ->whereHas('apiReferences', fn ($reference) => $reference->where('api_source', 'inaturalist'))
            ->inRandomOrder()
            ->limit(20)
            ->get()
            ->first(fn (Taxa $t) => ! empty($t->enriched_data['default_photo']['medium_url']));

        if (! $taxon) {
            return null;
        }

        $photo = $taxon->enriched_data['default_photo'];

        return [
            'name' => $taxon->common_name ?? $taxon->scientific_name,
            'scientificName' => $taxon->scientific_name,
            'image' => str_replace('/medium.', '/original.', $photo['medium_url']),
            'preview' => $photo['medium_url'],
            'author' => $photo['attribution'] ?? '',
            'group' => null,
        ];
    }

    private function key(string $group): string
    {
        return "auth_images:v2:{$group}";
    }
}
