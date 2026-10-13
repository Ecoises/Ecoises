<?php

namespace App\Services\Biodiversity;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SpeciesMerger
{
    /** Partículas de autor en minúscula que no son epítetos ("de Candolle", "van Hasselt"). */
    private const AUTHOR_PARTICLES = ['de', 'del', 'da', 'dos', 'du', 'la', 'le', 'van', 'von', 'der', 'ex', 'et', 'in'];

    /**
     * Limpia la autoría de un nombre científico:
     *   "Rhetus arcius (Linnaeus, 1763)"     → "Rhetus arcius"
     *   "Quiscalus lugubris Swainson, 1838"  → "Quiscalus lugubris"
     *   "Tangara gyrola toddi Bond, 1950"    → "Tangara gyrola toddi"
     *   "Quiscalus Vieillot, 1816"           → "Quiscalus"
     *   "Passiflora tarminiana × tripartita" → sin cambios (híbrido)
     * Respeta "sp." y los rangos infraespecíficos (subsp., var., f.).
     * El autor siempre empieza con mayúscula o paréntesis; los epítetos van en minúscula.
     */
    public static function stripAuthorship(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        $pattern = '/^(?<genus>[A-Z][a-z\-]+)(?<hybrid> [×x])? (?<species>[a-z\-]+\.?)'
            .'(?: (?<rank>subsp\.|ssp\.|var\.|f\.|subspecies) (?<infra>[a-z\-]+)| [×x] (?<cross>[a-z\-]+)| (?<infra2>[a-z\-]+)(?= |$))?/u';

        if (preg_match($pattern, $name, $m)) {
            $clean = $m['genus'].(! empty($m['hybrid']) ? ' × ' : ' ').$m['species'];

            if (! empty($m['cross'])) {
                $clean .= ' × '.$m['cross'];
            } elseif (! empty($m['rank']) && ! empty($m['infra'])) {
                $clean .= ' '.$m['rank'].' '.$m['infra'];
            } elseif (! empty($m['infra2']) && ! in_array($m['infra2'], self::AUTHOR_PARTICLES, true)) {
                $clean .= ' '.$m['infra2'];
            }

            return $clean;
        }

        // Solo género (o un taxón superior) seguido del autor.
        if (preg_match('/^([A-Z][a-z\-]+) (?:\(|[A-Z])/u', $name, $m)) {
            return $m[1];
        }

        return trim(preg_replace('/\s*\(.*?\)\s*$/', '', $name));
    }

    /** Lo que sobra del nombre después de quitar la parte científica: el autor y el año. */
    public static function authorship(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $clean = self::stripAuthorship($name);
        if ($clean === $name || ! str_starts_with($name, $clean)) {
            return null;
        }

        return trim(substr($name, strlen($clean)), " ,") ?: null;
    }

    /**
     * Mergea datos de GBIF e iNaturalist en un registro canónico único.
     * 
     * Resuelve sinonimias, elige la mejor foto, combina metadatos.
     * GBIF > iNaturalist en: taxonomía, conservación
     * iNaturalist > GBIF en: fotos, observaciones, datos de educación
     *
     * @param array $gbifResponse
     * @param array $inatResponse
     * @return array
     */
    public function merge(array $gbifResponse, array $inatResponse, string $originalName = ''): array
    {
        try {
            // Extraer primer elemento si es un array (ambos servicios devuelven ['data' => [objeto]])
            $gbifRaw = $gbifResponse['success'] ? $gbifResponse['data'] : null;
            $inatRaw = $inatResponse['success'] ? $inatResponse['data'] : null;
            
            // Si es un array, tomar el primer elemento
            $gbifData = is_array($gbifRaw) ? $gbifRaw[0] ?? null : $gbifRaw;
            $inatData = is_array($inatRaw) ? $inatRaw[0] ?? null : $inatRaw;

            if (!$gbifData && !$inatData) {
                return [
                    'success' => false,
                    'error' => 'Ni GBIF ni iNaturalist encontraron la especie',
                ];
            }

            // Resolver nombre científico canónico
            $scientificName = $this->resolveCanonicalName($gbifData, $inatData, $originalName);

            // Si solo una fuente tiene datos, usamos eso
            if (!$gbifData) {
                return $this->buildCanonicalRecord($scientificName, null, $inatData);
            }
            if (!$inatData) {
                return $this->buildCanonicalRecord($scientificName, $gbifData, null);
            }

            // Ambas fuentes coinciden — mergear
            return $this->buildCanonicalRecord($scientificName, $gbifData, $inatData);

        } catch (\Throwable $e) {
            Log::error('SpeciesMerger::merge error', [
                'error' => $e->getMessage(),
                'gbif_success' => $gbifResponse['success'] ?? false,
                'inat_success' => $inatResponse['success'] ?? false,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Resuelve el nombre científico canónico entre GBIF e iNaturalist.
     * 
     * GBIF es autoritativo para taxonomía. Si GBIF tiene el nombre, usarlo.
     * Si no, usar iNaturalist. Si ambos difieren, loguear pero preferir GBIF.
     *
     * @param array|null $gbifData
     * @param array|null $inatData
     * @return string
     */
    protected function resolveCanonicalName(?array $gbifData, ?array $inatData, string $originalName = ''): string
    {
        $gbifName = $gbifData['canonicalName'] 
            ?? $gbifData['scientificName'] 
            ?? $gbifData['scientific_name'] 
            ?? $gbifData['matchedName'] 
            ?? $gbifData['verbatim'] 
            ?? null;
        
        $inatName = $inatData['name'] 
            ?? $inatData['scientificName'] 
            ?? $inatData['scientific_name'] 
            ?? null;

        // Si ninguna fuente tiene nombre pero tenemos el original, usarlo
        if (!$gbifName && !$inatName) {
            if ($originalName) {
                return $originalName;
            }
            throw new \Exception('No se pudo resolver nombre científico de ninguna fuente');
        }

        if ($gbifName && $inatName && $gbifName !== $inatName) {
            Log::warning('SpeciesMerger: Nombre científico difiere entre fuentes', [
                'gbif' => $gbifName,
                'inat' => $inatName,
                'usando' => $gbifName,
            ]);
        }

        return $gbifName ?? $inatName;
    }

    /**
     * Construye un registro canónico combinado.
     *
     * @param string $scientificName
     * @param array|null $gbifData
     * @param array|null $inatData
     * @return array
     */
    protected function buildCanonicalRecord(
        string $scientificName,
        ?array $gbifData,
        ?array $inatData
    ): array {
        // GBIF es autoritativo para taxonomía linneana
        $taxonomyFromGbif = [];
        if ($gbifData) {
            $taxonomyFromGbif = [
                'kingdom' => $gbifData['kingdom'] ?? null,
                'phylum' => $gbifData['phylum'] ?? null,
                'class' => $gbifData['class'] ?? null,
                'order' => $gbifData['order'] ?? null,
                'family' => $gbifData['family'] ?? null,
                'genus' => $gbifData['genus'] ?? null,
                'species' => $gbifData['species'] ?? null,
            ];
        }

        // iNaturalist: fotos, nombres comunes, observaciones
        $commonName = null;
        if ($inatData) {
            $commonName = $inatData['preferred_common_name'] 
                ?? ($inatData['common_names'][0] ?? null);
        }

        $defaultPhoto = $this->selectBestPhoto($gbifData, $inatData);

        // Estado de conservación: GBIF > iNaturalist
        $conservationStatus = null;
        if ($gbifData) {
            $conservationStatus = $gbifData['conservationStatus'] ?? null;
        }
        if (!$conservationStatus && $inatData) {
            $conservationStatus = $inatData['conservationStatus'] ?? null;
        }

        // Status de establecimiento (nativo/endémico) — iNaturalist es mejor aquí
        $inatEstablishment = $inatData['establishment_means'] ?? [];
        $isNative = !in_array('introduced', $inatEstablishment, true);
        $isEndemic = in_array('endemic', $inatEstablishment, true);
        $isIntroduced = in_array('introduced', $inatEstablishment, true) || !empty($inatData['introduced']);

        // preferred_establishment_means es más confiable
        if ($inatData && !empty($inatData['preferred_establishment_means'])) {
            $preferred = $inatData['preferred_establishment_means'];
            $isNative = in_array($preferred, ['native', 'endemic']);
            $isEndemic = $preferred === 'endemic';
            $isIntroduced = $preferred === 'introduced';
        }

        return [
            'success' => true,
            'data' => [
                'scientificName' => $scientificName,
                'commonName' => $commonName,
                ...$taxonomyFromGbif,
                'conservationStatus' => $conservationStatus,
                'isNative' => $isNative,
                'isEndemic' => $isEndemic,
                'isIntroduced' => $isIntroduced,
                'defaultPhoto' => $defaultPhoto,
                'rank' => $gbifData['rank'] ?? null,
                'gbifTaxonKey' => $gbifData['taxonKey'] ?? $gbifData['key'] ?? null,
                'inatTaxonId' => $inatData['id'] ?? null,
                'gbifConfidence' => ($gbifData['matchType'] ?? null) === 'EXACT' ? 1.0 : 0.8,
                'inatConfidence' => 1.0,
                // Guardar datos crudos para acceso posterior
                'gbifData' => $gbifData,
                'inatData' => $inatData,
            ],
        ];
    }

    /**
     * Selecciona la mejor foto entre GBIF e iNaturalist.
     * 
     * iNaturalist típicamente tiene fotos mejor curadas y con más calidad.
     *
     * @param array|null $gbifData
     * @param array|null $inatData
     * @return string|null
     */
    protected function selectBestPhoto(?array $gbifData, ?array $inatData): ?string
    {
        // iNaturalist primero (mejor calidad)
        if ($inatData && isset($inatData['default_photo']) && is_array($inatData['default_photo'])) {
            return $inatData['default_photo']['medium_url']
                ?? $inatData['default_photo']['original_url']
                ?? null;
        }

        // GBIF como fallback
        if ($gbifData && !empty($gbifData['media']) && is_array($gbifData['media'])) {
            $image = $gbifData['media'][0] ?? null;
            return is_array($image) ? ($image['identifier'] ?? null) : null;
        }

        return null;
    }
}
