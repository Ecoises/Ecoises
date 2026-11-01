<?php

namespace Tests\Feature;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Services\Biodiversity\TaxonCatalogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpeciesGalleryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('taxa', function (Blueprint $table): void {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->unsignedBigInteger('inat_taxon_id')->nullable();
            $table->timestamps();
        });

        Schema::create('taxon_api_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxon_id');
            $table->string('api_source');
            $table->string('external_id')->nullable();
            $table->string('api_url')->nullable();
            $table->decimal('confidence_score', 3, 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('data')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    private function species(array $data): Taxa
    {
        static $n = 0;
        $taxon = Taxa::create(['scientific_name' => ['Panthera onca', 'Tremarctos ornatus', 'Iguana iguana', 'Ara macao'][$n++]]);
        TaxonApiReference::create(['taxon_id' => $taxon->id, 'api_source' => 'inaturalist', 'external_id' => '41970', 'data' => $data]);

        return $taxon->load('apiReferences');
    }

    public function test_only_a_species_without_gallery_and_not_checked_this_month_needs_one(): void
    {
        $catalog = app(TaxonCatalogService::class);
        $photo = ['url' => 'https://example.org/a.jpg'];

        $this->assertTrue($catalog->needsGallery($this->species(['default_photo' => $photo, 'gallery' => []])));
        $this->assertFalse($catalog->needsGallery($this->species(['default_photo' => $photo, 'gallery' => [$photo]])));
        // Revisada hace poco y sin más fotos en iNaturalist: no se vuelve a pedir.
        $this->assertFalse($catalog->needsGallery($this->species(['gallery' => [], 'gallery_checked_at' => now()->subDays(3)->toIso8601String()])));
        $this->assertTrue($catalog->needsGallery($this->species(['gallery' => [], 'gallery_checked_at' => now()->subMonths(2)->toIso8601String()])));
    }
}
