<?php

namespace Tests\Feature;

use App\Models\ColombiaSpeciesList;
use App\Models\Taxa;
use App\Services\Biodiversity\ConservationLabelService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConservationLabelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('taxa', function (Blueprint $table): void {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->string('gbif_taxon_key')->nullable();
            $table->boolean('is_native')->nullable();
            $table->boolean('is_endemic')->nullable();
            $table->boolean('is_introduced')->nullable();
            $table->string('iucn_category', 2)->nullable();
            $table->string('iucn_taxon_id')->nullable();
            $table->timestamp('iucn_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('colombia_species_lists', function (Blueprint $table): void {
            $table->id();
            $table->string('list');
            $table->string('gbif_taxon_key')->nullable();
            $table->string('scientific_name');
            $table->string('category')->nullable();
            $table->timestamps();
            $table->unique(['list', 'scientific_name']);
        });
    }

    public function test_official_lists_take_precedence_and_cite_their_source(): void
    {
        $bear = Taxa::create(['scientific_name' => 'Tremarctos ornatus', 'gbif_taxon_key' => '2433401', 'is_native' => true, 'iucn_category' => 'VU', 'iucn_taxon_id' => '22066']);
        $chachalaca = Taxa::create(['scientific_name' => 'Ortalis columbiana', 'gbif_taxon_key' => '5229460', 'iucn_category' => 'LC']);
        $earthworm = Taxa::create(['scientific_name' => 'Pontoscolex corethrurus', 'is_native' => true]);

        ColombiaSpeciesList::insert([
            ['list' => ColombiaSpeciesList::THREAT, 'gbif_taxon_key' => '2433401', 'scientific_name' => 'Tremarctos ornatus', 'category' => 'VU', 'created_at' => now(), 'updated_at' => now()],
            ['list' => ColombiaSpeciesList::ENDEMIC_BIRDS, 'gbif_taxon_key' => '5229460', 'scientific_name' => 'Ortalis columbiana', 'category' => 'endemic', 'created_at' => now(), 'updated_at' => now()],
            ['list' => ColombiaSpeciesList::INTRODUCED, 'gbif_taxon_key' => null, 'scientific_name' => 'Pontoscolex corethrurus', 'category' => 'introduced', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $labels = app(ConservationLabelService::class)->labelsForMany(collect([$bear, $chachalaca, $earthworm]));

        $this->assertSame('VU', $labels[$bear->id]['national']['code']);
        $this->assertSame('MinAmbiente · Resolución 0126 de 2024', $labels[$bear->id]['national']['source']);
        $this->assertSame('Global', $labels[$bear->id]['global']['scope']);
        $this->assertSame('native', $labels[$bear->id]['origin']['status']);

        $this->assertNull($labels[$chachalaca->id]['national']);
        $this->assertSame('Instituto Humboldt', $labels[$chachalaca->id]['origin']['source']);

        // GRIIS gana sobre una bandera "nativa" de iNaturalist.
        $this->assertSame('introduced', $labels[$earthworm->id]['origin']['status']);
        $this->assertSame('GRIIS Colombia', $labels[$earthworm->id]['origin']['source']);
    }

    public function test_iucn_categories_come_from_gbif_without_a_token(): void
    {
        $taxon = Taxa::create(['scientific_name' => 'Ramphocelus carbo', 'gbif_taxon_key' => '2488442']);
        Http::fake(['api.gbif.org/v1/species/2488442/iucnRedListCategory' => Http::response(['code' => 'LC', 'iucnTaxonID' => '22722507'])]);

        app(ConservationLabelService::class)->syncIucn(collect([$taxon]));

        $taxon->refresh();
        $this->assertSame('LC', $taxon->iucn_category);
        $this->assertSame('22722507', $taxon->iucn_taxon_id);
        $this->assertNotNull($taxon->iucn_synced_at);
    }

    public function test_lists_are_downloaded_and_bird_endemism_is_read_from_descriptions(): void
    {
        Http::fake(function ($request) {
            $url = urldecode((string) $request->url());

            return match (true) {
                str_contains($url, '09174029') => Http::response(['endOfRecords' => true, 'results' => [
                    ['key' => 1, 'canonicalName' => 'Tremarctos ornatus', 'nubKey' => 2433401, 'threatStatuses' => ['VULNERABLE']],
                ]]),
                str_contains($url, 'b08f433a') => Http::response(['endOfRecords' => true, 'results' => [
                    ['key' => 10, 'canonicalName' => 'Ortalis columbiana', 'nubKey' => 5229460],
                    ['key' => 11, 'canonicalName' => 'Accipiter collaris', 'nubKey' => 2480593],
                ]]),
                str_contains($url, '/species/10/descriptions') => Http::response(['results' => [['type' => 'Distribución', 'description' => 'Endémica']]]),
                str_contains($url, '/species/11/descriptions') => Http::response(['results' => [['type' => 'Distribución', 'description' => 'Casi endémica']]]),
                str_contains($url, '168568e7') => Http::response(['endOfRecords' => true, 'results' => [
                    ['key' => 20, 'canonicalName' => 'Pontoscolex corethrurus', 'nubKey' => 2308741],
                ]]),
                default => Http::response([], 404),
            };
        });

        $counts = app(ConservationLabelService::class)->syncLists();

        $this->assertSame([ColombiaSpeciesList::THREAT => 1, ColombiaSpeciesList::ENDEMIC_BIRDS => 2, ColombiaSpeciesList::INTRODUCED => 1], $counts);
        $this->assertSame('VU', ColombiaSpeciesList::where('scientific_name', 'Tremarctos ornatus')->value('category'));
        $this->assertSame('endemic', ColombiaSpeciesList::where('scientific_name', 'Ortalis columbiana')->value('category'));
        $this->assertSame('near_endemic', ColombiaSpeciesList::where('scientific_name', 'Accipiter collaris')->value('category'));
    }
}
