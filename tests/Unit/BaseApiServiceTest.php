<?php

namespace Tests\Unit;

use App\Services\Api\BaseApiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BaseApiServiceTest extends TestCase
{
    #[Test]
    public function it_returns_a_failed_result_when_an_external_api_times_out(): void
    {
        Http::fake(function () {
            throw new ConnectionException('External API timed out');
        });

        $service = new class extends BaseApiService
        {
            protected $apiName = 'test';

            protected function initialize(): void
            {
                $this->config = [
                    'base_url' => 'https://example.test',
                    'timeout' => 1,
                ];
            }

            public function requestWithoutCache(): array
            {
                return $this->makeRequest('get', '/taxa/1000', [], false);
            }

            public function getTaxonById(string $id): array
            {
                return [];
            }

            public function searchTaxon(string $scientificName): array
            {
                return [];
            }

            public function getTaxonObservations(string $taxonId, array $params = []): array
            {
                return [];
            }

            public function getLocationInfo(string $locationId): array
            {
                return [];
            }

            public function getApiInfo(): array
            {
                return [];
            }
        };

        $result = $service->requestWithoutCache();

        $this->assertFalse($result['success']);
        $this->assertSame('test', $result['api']);
        $this->assertSame('/taxa/1000', $result['endpoint']);
        $this->assertSame('External API timed out', $result['error']['message']);
    }
}
