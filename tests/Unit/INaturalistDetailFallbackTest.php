<?php

namespace Tests\Unit;

use App\Services\Api\INaturalistService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class INaturalistDetailFallbackTest extends TestCase
{
    #[Test]
    public function it_recovers_a_rich_taxon_detail_from_the_official_website(): void
    {
        Http::fake([
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
