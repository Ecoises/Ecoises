<?php

namespace Tests\Feature;

use App\Services\Biodiversity\ExplorerService;
use Mockery;
use Tests\TestCase;

class ExplorerEndpointTest extends TestCase
{
    private function page(array $data, int $total): array
    {
        return [
            'success' => true,
            'data' => $data,
            'meta' => ['pagination' => ['total' => $total, 'per_page' => 24, 'current_page' => 1, 'last_page' => 1]],
        ];
    }

    public function test_it_forwards_filters_to_the_citizen_science_list(): void
    {
        $service = Mockery::mock(ExplorerService::class);
        $service->shouldReceive('citizenScience')
            ->once()
            ->withArgs(fn (array $filters) => $filters['lat'] == 10.343111
                && $filters['lng'] == -73.375793
                && $filters['radius'] === 25
                && $filters['group'] === 'Mammalia'
                && $filters['q'] === 'Bos taurus'
                && $filters['native'] === true
                && $filters['endemic'] === false
                && $filters['threatened'] === true
                && $filters['order'] === 'random'
                && $filters['page'] === 2)
            ->andReturn($this->page([['id' => 1, 'scientific_name' => 'Bos taurus', 'source' => 'inaturalist']], 1));
        $this->app->instance(ExplorerService::class, $service);

        $this->getJson('/api/explorer?'.http_build_query([
            'lat' => 10.343111, 'lng' => -73.375793, 'radius' => 25, 'group' => 'Mammalia',
            'q' => 'Bos taurus', 'native' => 1, 'endemic' => 0, 'threatened' => 1,
            'order' => 'random', 'seed' => 'abc', 'page' => 2,
        ]))
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.source', 'inaturalist');
    }

    public function test_it_rejects_invalid_filters(): void
    {
        $this->getJson('/api/explorer?lat=100&lng=-73&radius=500&group=Dinosauria')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lat', 'radius', 'group']);
    }

    public function test_scientific_records_report_when_a_zone_is_still_building(): void
    {
        $service = Mockery::mock(ExplorerService::class);
        $service->shouldReceive('scientificRecords')->once()->andReturn(['status' => 'building'] + $this->page([], 0));
        $this->app->instance(ExplorerService::class, $service);

        $this->getJson('/api/explorer/scientific?group=Aves')
            ->assertOk()
            ->assertJsonPath('status', 'building');
    }

    public function test_an_upstream_failure_returns_a_clear_error(): void
    {
        $service = Mockery::mock(ExplorerService::class);
        $service->shouldReceive('citizenScience')->once()->andReturn(['success' => false, 'error' => 'timeout']);
        $this->app->instance(ExplorerService::class, $service);

        $this->getJson('/api/explorer')
            ->assertStatus(502)
            ->assertJsonPath('success', false);
    }
}
