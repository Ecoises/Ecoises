<?php

namespace App\Services\Biodiversity;

/**
 * Traduce el "establishment means" de iNaturalist (nativa, endémica,
 * introducida) a las banderas locales de la tabla `taxa`.
 *
 * Es una clase pura: no consulta APIs ni escribe logs, así que se puede usar
 * en accessors del modelo sin costo.
 */
class EstablishmentStatusResolver
{
    public const UNKNOWN = ['is_native' => false, 'is_endemic' => false, 'is_introduced' => false, 'status' => 'unknown'];

    private const MAP = [
        'endemic' => ['is_native' => true, 'is_endemic' => true, 'is_introduced' => false, 'status' => 'endemic'],
        'native' => ['is_native' => true, 'is_endemic' => false, 'is_introduced' => false, 'status' => 'native'],
        'introduced' => ['is_native' => false, 'is_endemic' => false, 'is_introduced' => true, 'status' => 'introduced'],
    ];

    /**
     * Busca el estatus en el orden de confiabilidad de iNaturalist:
     * preferred_establishment_means → establishment_means → listed_taxa del
     * lugar preferido (Colombia) → banderas booleanas.
     *
     * @return array{is_native: bool, is_endemic: bool, is_introduced: bool, status: string}
     */
    public function resolve(array $apiData, ?int $placeId = null): array
    {
        if (! empty($apiData['preferred_establishment_means'])) {
            return $this->fromMeans($apiData['preferred_establishment_means']);
        }

        $means = $apiData['establishment_means'] ?? null;
        if (is_array($means)) {
            $value = $means['establishment_means'] ?? $means[0]['establishment_means'] ?? null;
            if ($value) {
                return $this->fromMeans($value);
            }
        }

        $placeId ??= (int) config('services.inaturalist.preferred_place_id', 7196);
        foreach ($apiData['listed_taxa'] ?? [] as $listed) {
            if ((int) ($listed['place']['id'] ?? 0) === $placeId && ! empty($listed['establishment_means'])) {
                return $this->fromMeans($listed['establishment_means']);
            }
        }

        foreach (['endemic', 'introduced', 'native'] as $flag) {
            if (! empty($apiData[$flag])) {
                return self::MAP[$flag];
            }
        }

        return self::UNKNOWN;
    }

    public function fromMeans(?string $means): array
    {
        return self::MAP[strtolower((string) $means)] ?? self::UNKNOWN;
    }
}
