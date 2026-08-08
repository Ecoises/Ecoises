<?php

namespace Tests\Feature;

use App\Services\ExplorerService;
use Mockery;
use Tests\TestCase;

class ExplorerEndpointTest extends TestCase
{
    public function test_it_forwards_geo_filters_and_returns_pagination_metadata(): void
    {
        $service = Mockery::mock(ExplorerService::class);
        $service->shouldReceive('explore')
            ->once()
            ->withArgs(function (float $lat, float $lng, array $filters): bool {
                return $lat === 10.343111
                    && $lng === -73.375793
                    && $filters['radius'] === 25
                    && $filters['page'] === 2
                    && $filters['per_page'] === 25
                    && $filters['q'] === 'Bos taurus'
                    && $filters['iconic_taxa'] === 'Mammalia'
                    && $filters['native'] === true
                    && $filters['endemic'] === false
                    && $filters['threatened'] === true;
            })
            ->andReturn([
                'success' => true,
                'data' => [['id' => 1, 'scientific_name' => 'Bos taurus']],
                'meta' => [
                    'radius_km' => 25,
                    'pagination' => [
                        'total' => 1,
                        'per_page' => 25,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
            ]);

        $this->app->instance(ExplorerService::class, $service);

        $response = $this->getJson('/api/explorer/nearby?' . http_build_query([
            'lat' => 10.343111,
            'lng' => -73.375793,
            'radius' => 25,
            'page' => 2,
            'per_page' => 25,
            'q' => 'Bos taurus',
            'iconic_taxa' => 'Mammalia',
            'native' => 'true',
            'endemic' => 'false',
            'threatened' => 'true',
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('meta.radius_km', 25)
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.scientific_name', 'Bos taurus');
    }

    public function test_it_rejects_an_invalid_geographic_request(): void
    {
        $this->getJson('/api/explorer/nearby?lat=100&lng=-73&radius=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'radius']);
    }
    public function test_it_exposes_the_dynamic_national_catalog(): void
    {
        $service = Mockery::mock(ExplorerService::class);
        $service->shouldReceive('exploreNational')
            ->once()
            ->withArgs(fn (array $filters): bool => $filters['page'] === 1
                && $filters['per_page'] === 25
                && $filters['iconic_taxa'] === 'Plantae')
            ->andReturn([
                'success' => true,
                'data' => [['id' => 2, 'scientific_name' => 'Guazuma ulmifolia']],
                'meta' => [
                    'scope' => 'national',
                    'pagination' => [
                        'total' => 1,
                        'per_page' => 25,
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ],
            ]);

        $this->app->instance(ExplorerService::class, $service);

        $this->getJson('/api/explorer/national?iconic_taxa=Plantae')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'national')
            ->assertJsonPath('data.0.scientific_name', 'Guazuma ulmifolia');
    }
}