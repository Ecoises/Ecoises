<?php

namespace App\Services;

class ConservationStatusResolver
{
    private const VALID_CODES = ['LC', 'NT', 'VU', 'EN', 'CR', 'EW', 'EX', 'DD', 'NE'];

    private const IUCN_TO_CODE = [
        0 => 'NE',
        5 => 'DD',
        10 => 'LC',
        20 => 'NT',
        30 => 'VU',
        40 => 'EN',
        50 => 'CR',
        90 => 'EW',
        100 => 'EX',
    ];

    /**
     * Resolve an iNaturalist conservation payload without assuming that the
     * first territorial status applies to Colombia.
     *
     * @return array{code: ?string, status_name: ?string, iucn: ?int, authority: ?string, url: ?string, place_id: ?int, scope: ?string}
     */
    public function resolve(mixed $payload, int $preferredPlaceId = 7196): array
    {
        $empty = [
            'code' => null,
            'status_name' => null,
            'iucn' => null,
            'authority' => null,
            'url' => null,
            'place_id' => null,
            'scope' => null,
        ];

        $candidates = $this->candidates($payload);
        if ($candidates === []) {
            return $empty;
        }

        $preferred = collect($candidates)->first(
            fn (array $status) => (int) ($status['place_id'] ?? 0) === $preferredPlaceId
        );
        $global = collect($candidates)->first(
            fn (array $status) => empty($status['place_id'])
        );
        $selected = $preferred ?? $global;

        // A status for another territory must not be presented as Colombian or global.
        if (!$selected) {
            return $empty;
        }

        $code = $this->normalizeCode(
            $selected['status'] ?? $selected['code'] ?? $selected['status_name'] ?? null,
            $selected['iucn'] ?? null
        );
        if (!$code) {
            return $empty;
        }

        $placeId = !empty($selected['place_id']) ? (int) $selected['place_id'] : null;

        return [
            'code' => $code,
            'status_name' => $this->label($code),
            'iucn' => isset($selected['iucn']) ? (int) $selected['iucn'] : null,
            'authority' => $selected['authority'] ?? null,
            'url' => $selected['url'] ?? null,
            'place_id' => $placeId,
            'scope' => $placeId === $preferredPlaceId ? 'colombia' : 'global',
        ];
    }

    private function candidates(mixed $payload): array
    {
        if (!is_array($payload) || $payload === []) {
            return is_string($payload) ? [['status' => $payload]] : [];
        }

        if (array_key_exists('status', $payload) || array_key_exists('code', $payload)) {
            return [$payload];
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    private function normalizeCode(mixed $status, mixed $iucn): ?string
    {
        $normalized = strtoupper(trim(str_replace(['-', '_'], ' ', (string) $status)));
        $aliases = [
            'LEAST CONCERN' => 'LC',
            'NEAR THREATENED' => 'NT',
            'VULNERABLE' => 'VU',
            'ENDANGERED' => 'EN',
            'CRITICALLY ENDANGERED' => 'CR',
            'EXTINCT IN THE WILD' => 'EW',
            'EXTINCT' => 'EX',
            'DATA DEFICIENT' => 'DD',
            'NOT EVALUATED' => 'NE',
        ];
        $code = $aliases[$normalized] ?? $normalized;

        if (in_array($code, self::VALID_CODES, true)) {
            return $code;
        }

        return is_numeric($iucn) ? (self::IUCN_TO_CODE[(int) $iucn] ?? null) : null;
    }

    private function label(string $code): string
    {
        return [
            'LC' => 'Preocupación menor',
            'NT' => 'Casi amenazada',
            'VU' => 'Vulnerable',
            'EN' => 'En peligro',
            'CR' => 'En peligro crítico',
            'EW' => 'Extinta en estado silvestre',
            'EX' => 'Extinta',
            'DD' => 'Datos insuficientes',
            'NE' => 'No evaluada',
        ][$code];
    }
}
