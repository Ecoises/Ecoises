<?php

namespace App\Services\Biodiversity;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Services\ExternalApi\INaturalistService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escribe en el catálogo local (`taxa` + `taxon_api_references`) los datos
 * que llegan de iNaturalist. Es el único lugar donde un payload de iNaturalist
 * se convierte en un registro local, para que las reglas de procedencia de
 * nombres, estatus y conservación vivan en un solo sitio.
 */
class TaxonSyncService
{
    private const TAXONOMY_FIELDS = [
        'kingdom' => 'kingdom',
        'phylum' => 'phylum',
        'class' => 'class',
        'order' => 'order_name',
        'family' => 'family',
        'genus' => 'genus',
        'species' => 'species',
    ];

    public function __construct(
        private readonly INaturalistService $iNaturalist,
        private readonly ConservationStatusResolver $conservation,
        private readonly EstablishmentStatusResolver $establishment,
    ) {}

    /**
     * Descarga un taxón de iNaturalist por su ID externo y lo guarda.
     */
    public function enrichFromINaturalistId(string $externalId): ?Taxa
    {
        $response = $this->iNaturalist->getTaxonById($externalId);

        if (! ($response['success'] ?? false) || empty($response['data'])) {
            return null;
        }

        return $this->saveFromINaturalist($response['data']);
    }

    /**
     * Crea o actualiza un taxón a partir del payload normalizado de iNaturalist.
     */
    public function saveFromINaturalist(array $taxonData): ?Taxa
    {
        $scientificName = $taxonData['scientific_name'] ?? $taxonData['name'] ?? null;
        if (! $scientificName) {
            return null;
        }

        try {
            return DB::transaction(function () use ($scientificName, $taxonData): Taxa {
                $taxon = Taxa::firstOrNew(['scientific_name' => $scientificName]);

                // El nombre del catálogo local es canónico: iNaturalist solo lo completa.
                $commonName = $taxonData['preferred_common_name'] ?? $taxonData['common_names'][0]['name'] ?? null;
                if ($commonName && empty($taxon->common_name)) {
                    $taxon->common_name = $commonName;
                }

                foreach ($this->extractTaxonomy($taxonData) as $rank => $name) {
                    if ($name && isset(self::TAXONOMY_FIELDS[$rank])) {
                        $taxon->{self::TAXONOMY_FIELDS[$rank]} = $name;
                    }
                }

                $flags = $this->establishment->resolve($taxonData);
                if ($flags['status'] !== 'unknown') {
                    $taxon->is_native = $flags['is_native'];
                    $taxon->is_endemic = $flags['is_endemic'];
                    $taxon->is_introduced = $flags['is_introduced'];
                }

                $this->applyConservation($taxon, $taxonData);

                $taxon->observation_count = max(
                    (int) ($taxon->observation_count ?? 0),
                    (int) ($taxonData['observations_count'] ?? 0)
                );
                $taxon->last_observed_at = now();
                $taxon->save();

                TaxonApiReference::updateOrCreate(
                    ['taxon_id' => $taxon->id, 'api_source' => 'inaturalist'],
                    [
                        'external_id' => $taxonData['id'] ?? null,
                        'api_url' => $taxonData['url'] ?? null,
                        'confidence_score' => 1.0,
                        'is_primary' => true,
                        'last_verified_at' => now(),
                        'data' => $taxonData,
                    ]
                );

                return $taxon->load('apiReferences');
            });
        } catch (\Throwable $e) {
            Log::error('Error guardando taxón de iNaturalist: '.$e->getMessage(), ['scientific_name' => $scientificName]);

            return null;
        }
    }

    private function applyConservation(Taxa $taxon, array $taxonData): void
    {
        $conservation = $this->conservation->resolve(
            $taxonData['conservation_statuses'] ?? $taxonData['conservation_status'] ?? null,
            (int) config('services.inaturalist.preferred_place_id', 7196)
        );
        $taxon->conservation_status_synced_at = now();

        if ($conservation['code']) {
            $taxon->fill([
                'conservation_status' => $conservation['code'],
                'conservation_status_source' => 'inaturalist',
                'conservation_status_scope' => $conservation['scope'],
                'conservation_status_authority' => $conservation['authority'],
                'conservation_status_url' => $conservation['url'],
            ]);
        } elseif (array_key_exists('conservation_status', $taxonData)
            && ($taxon->conservation_status === 'NE' || $taxon->conservation_status_source === 'inaturalist')) {
            // Que iNaturalist no tenga evaluación no equivale a la categoría formal NE.
            $taxon->fill([
                'conservation_status' => null,
                'conservation_status_source' => 'inaturalist',
                'conservation_status_scope' => null,
                'conservation_status_authority' => null,
                'conservation_status_url' => null,
            ]);
        } elseif ($taxon->conservation_status && ! $taxon->conservation_status_source) {
            $taxon->conservation_status_source = 'legacy';
        }
    }

    /**
     * @return array<string, ?string> rango => nombre
     */
    private function extractTaxonomy(array $taxonData): array
    {
        $taxonomy = [];
        foreach ($taxonData['ancestors'] ?? [] as $ancestor) {
            $taxonomy[strtolower($ancestor['rank'] ?? '')] = $ancestor['name'] ?? null;
        }

        $name = $taxonData['name'] ?? '';
        if (str_contains($name, ' ')) {
            [$taxonomy['genus'], $taxonomy['species']] = explode(' ', $name, 2);
        }

        return $taxonomy;
    }
}
