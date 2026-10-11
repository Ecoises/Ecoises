<?php

namespace Tests\Unit;

use App\Services\ExternalApi\INaturalistService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class INaturalistDetailFallbackTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('unified_api_caches', function (Blueprint $table): void {
            $table->id();
            $table->string('cache_key')->unique();
            $table->string('cache_type')->nullable();
            $table->string('api_source')->nullable();
            $table->string('data_type')->nullable();
            $table->text('request_url')->nullable();
            $table->json('request_params')->nullable();
            $table->json('response_data');
            $table->json('response_metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('last_accessed_at')->nullable();
            $table->integer('hit_count')->default(0);
            $table->timestamps();
        });
    }

    #[Test]
    public function it_recovers_a_rich_taxon_detail_from_the_official_website_when_the_api_fails(): void
    {
        Http::fake([
            'api.inaturalist.org/*' => Http::response(['error' => 'down'], 503),
            'www.inaturalist.org/*' => Http::response([
                'id' => 5050,
                'name' => 'Tigrisoma fasciatum',
                'rank' => 'species',
                'wikipedia_summary' => 'Resumen científico.',
                'default_photo' => [
                    'id' => 10,
                    'medium_url' => 'https://images.example/photo.jpg',
                ],
                'taxon_photos' => [[
                    'photo' => [
                        'id' => 10,
                        'medium_url' => 'https://images.example/photo.jpg',
                    ],
                ]],
                'taxon_names' => [[
                    'lexicon' => 'Spanish',
                    'name' => 'Hocó oscuro',
                ]],
            ]),
        ]);

        $result = app(INaturalistService::class)->getTaxonById('5050');

        $this->assertTrue($result['success']);
        $this->assertSame('inaturalist_website_fallback', $result['source']);
        $this->assertNull($result['data']['common_name']);
        $this->assertSame('Resumen científico.', $result['data']['wikipedia_summary']);
        $this->assertCount(1, $result['data']['gallery']);
    }
}
