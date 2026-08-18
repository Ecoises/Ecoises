<?php

namespace Tests\Unit;

use App\Services\Api\GbifService;
use Tests\TestCase;

class GbifNearbySearchTest extends TestCase
{
    public function test_it_uses_a_real_radius_and_species_facets(): void
    {
        $service = new class extends GbifService {
            public array $capturedParams = [];

            protected function makeRequest(string $method, string $endpoint, array $params = [], bool $useCache = true): array
            {
                $this->capturedParams = $params;

                return [
                    'success' => true,
                    'cached' => false,
                    'data' => [
                        'count' => 42,
                        'facets' => [[
                            'field' => 'speciesKey',
                            'counts' => [
                                ['name' => '1', 'count' => 9],
                                ['name' => '2', 'count' => 4],
                            ],
                        ]],
                    ],
                ];
            }

            public function searchTaxon(string $scientificName): array
            {
                return ['success' => true, 'data' => [['usageKey' => 212, 'matchType' => 'EXACT']]];
            }
        };

        $result = $service->searchSpeciesByRegion(10.343111, -73.375793, 25, [
            'q' => 'Tyrannus',
            'iconic_taxa' => 'Aves',
            'native' => true,
        ]);

        $this->assertSame('10.343111,-73.375793,25km', $service->capturedParams['geoDistance']);
        $this->assertSame('speciesKey', $service->capturedParams['facet']);
        $this->assertSame(0, $service->capturedParams['limit']);
        $this->assertSame(212, $service->capturedParams['taxonKey']);
        $this->assertArrayNotHasKey('q', $service->capturedParams);
        $this->assertSame('Native', $service->capturedParams['establishmentMeans']);
        $this->assertSame(42, $result['data']['occurrence_total']);
        $this->assertSame('1', $result['data']['buckets'][0]['species_key']);
        $this->assertSame(9, $result['data']['buckets'][0]['occurrence_count']);
    }
    public function test_it_returns_an_empty_catalog_when_the_name_has_no_taxonomic_match(): void
    {
        $service = new class extends GbifService {
            public int $occurrenceRequests = 0;

            protected function makeRequest(string $method, string $endpoint, array $params = [], bool $useCache = true): array
            {
                $this->occurrenceRequests++;

                return ['success' => true, 'data' => []];
            }

            public function searchTaxon(string $scientificName): array
            {
                return ['success' => true, 'data' => [['matchType' => 'NONE']]];
            }
        };

        $result = $service->searchSpeciesByRegion(10.343111, -73.375793, 25, [
            'q' => 'nombre inexistente',
        ]);

        $this->assertSame(0, $service->occurrenceRequests);
        $this->assertSame([], $result['data']['buckets']);
        $this->assertSame(0, $result['data']['occurrence_total']);
    }
    public function test_it_uses_colombia_as_the_scope_for_the_national_catalog(): void
    {
        $service = new class extends GbifService {
            public array $capturedParams = [];

            protected function makeRequest(string $method, string $endpoint, array $params = [], bool $useCache = true): array
            {
                $this->capturedParams = $params;

                return [
                    'success' => true,
                    'data' => [
                        'count' => 100,
                        'facets' => [[
                            'field' => 'SPECIES_KEY',
                            'counts' => [['name' => '10', 'count' => 20]],
                        ]],
                    ],
                ];
            }
        };

        $result = $service->searchSpeciesByCountry('co');

        $this->assertSame('CO', $service->capturedParams['country']);
        $this->assertArrayNotHasKey('geoDistance', $service->capturedParams);
        $this->assertSame('10', $result['data']['buckets'][0]['species_key']);
    }

    public function test_it_prioritizes_a_spanish_name_documented_in_colombia(): void
    {
        $service = new class extends GbifService {
            public function selectName(array $records): ?string
            {
                return $this->selectColombianVernacularName($records);
            }
        };

        $name = $service->selectName([
            ['vernacularName' => 'Hocó oscuro', 'language' => 'spa'],
            ['vernacularName' => 'Garza Tigre Barreteada', 'language' => 'spa', 'country' => 'EC'],
            ['vernacularName' => 'Vaco Cabecinegro', 'language' => '', 'area' => 'CO:05756 | CO:05652'],
            ['vernacularName' => 'Garza tigre de río', 'language' => 'spa', 'source' => 'Inventario de aves de Colombia'],
        ]);

        $this->assertSame('Garza tigre de río', $name);
    }
}
