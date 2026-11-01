<?php

namespace App\Services\Biodiversity;

use App\Jobs\FetchGroupPhotosJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Foto de respaldo para las especies sin fotografía propia: la foto que
 * iNaturalist usa para su género o, si el género no tiene, para su familia
 * (como hace iNaturalist en sus fichas). Las tarjetas la muestran marcada.
 *
 * iNaturalist tarda alrededor de un segundo por consulta, así que el
 * explorador solo lee la caché; los grupos que faltan se buscan en segundo
 * plano (FetchGroupPhotosJob) y aparecen al recargar la lista.
 */
class GroupPhotoService
{
    private const ENDPOINT = 'https://api.inaturalist.org/v1/taxa';

    /**
     * Pone la foto del género o la familia en las tarjetas sin foto propia.
     * Las tarjetas necesitan `genus` y, si se conoce, `family`.
     */
    public function fillCards(array $cards): array
    {
        $missing = [];

        $cards = array_map(function (array $card) use (&$missing) {
            if (! empty($card['default_photo']['url'])) {
                return $card;
            }

            $pending = false;
            $groups = array_filter(['genus' => $card['genus'] ?? null, 'family' => $card['family'] ?? null]);
            foreach ($groups as $rank => $name) {
                $cached = Cache::get($this->key($rank, $name));
                if ($cached === null) {
                    $missing["{$rank}:{$name}"] = [$rank, $name];
                    $pending = true;

                    continue;
                }
                if ($cached !== []) {
                    $card['default_photo'] = ['url' => $cached['url'], 'attribution' => $cached['attribution']];
                    $card['photo_scope'] = ['rank' => $rank, 'name' => $name];

                    return $card;
                }
            }

            $card['photo_pending'] = $pending;

            return $card;
        }, $cards);

        if ($missing !== []) {
            FetchGroupPhotosJob::dispatch(array_values($missing))->onQueue('interactive');
        }

        return $cards;
    }

    /**
     * Busca y guarda la foto de un género o familia. Sin foto en iNaturalist se
     * guarda vacío una semana para no volver a preguntar en cada visita.
     */
    public function fetch(string $rank, string $name): void
    {
        if (Cache::has($this->key($rank, $name))) {
            return;
        }

        try {
            $response = Http::timeout(15)->get(self::ENDPOINT, ['q' => $name, 'rank' => $rank, 'per_page' => 10, 'locale' => 'es']);
        } catch (\Throwable $e) {
            Log::warning('No se pudo traer la foto del grupo', ['rank' => $rank, 'name' => $name, 'message' => $e->getMessage()]);

            return;
        }

        if (! $response->successful()) {
            return;
        }

        $taxon = collect($response->json('results', []))
            ->first(fn (array $result) => strcasecmp($result['name'] ?? '', $name) === 0 && ($result['rank'] ?? null) === $rank);
        $photo = $taxon['default_photo'] ?? null;
        $url = $photo['medium_url'] ?? $photo['url'] ?? null;

        $url
            ? Cache::put($this->key($rank, $name), ['url' => $url, 'attribution' => $photo['attribution'] ?? null], now()->addDays(30))
            : Cache::put($this->key($rank, $name), [], now()->addWeek());
    }

    private function key(string $rank, string $name): string
    {
        return "group_photo:v1:{$rank}:".mb_strtolower($name);
    }
}
