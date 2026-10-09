<?php

namespace Tests\Feature;

use App\Models\Taxa;
use App\Services\Biodiversity\EcologicalImportanceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EcologicalImportanceTest extends TestCase
{
    private const PLANT = 'Eukaryota | Plantae | Tracheophyta | Magnoliopsida';

    private const INSECT = 'Animalia | Arthropoda | Insecta';

    private const SNAKE = 'Animalia | Chordata | Reptilia | Squamata';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('taxa', function (Blueprint $table): void {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->string('common_name')->nullable();
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

    public function test_it_builds_species_specific_roles_from_globi_records(): void
    {
        $bird = Taxa::create(['scientific_name' => 'Ramphocelus carbo', 'common_name' => 'Fueguero oscuro']);
        $guava = Taxa::create(['scientific_name' => 'Psidium guajava', 'common_name' => 'Guayaba']);

        Http::fake([
            'api.globalbioticinteractions.org/*sourceTaxon*' => Http::response(['data' => [
                ['dispersalVectorOf', 'Psidium guajava', self::PLANT],
                ['dispersalVectorOf', 'Cecropia peltata', self::PLANT],
                ['eats', 'Ficus americana', self::PLANT],
                ['eats', 'Phasmida', self::INSECT], // un solo registro: no alcanza para afirmar el rol
                ['hasPathogen', 'Hapavirus flanders', 'root | Viruses'],
            ]]),
            'api.globalbioticinteractions.org/*targetTaxon*' => Http::response(['data' => [
                ['preysOn', 'Oxybelis fulgidus', self::SNAKE],
                ['preysOn', 'Oxybelis fulgidus', self::SNAKE],
                ['preysOn', 'Oxybelis fulgidus', self::SNAKE],
            ]]),
        ]);

        $service = app(EcologicalImportanceService::class);
        $this->assertTrue($service->sync($bird));

        $result = $service->present($bird->load('apiReferences'));
        $roles = collect($result['roles'])->keyBy('key');

        $this->assertSame(['seed_disperser', 'food_source'], $roles->keys()->all());
        $this->assertSame(2, $roles['seed_disperser']['partner_count']);
        $this->assertSame($guava->id, $roles['seed_disperser']['examples'][0]['taxon_id']);
        $this->assertStringContainsString('Fueguero oscuro dispersa las semillas de Guayaba (Psidium guajava)', $result['summary']);
        $this->assertStringContainsString('es alimento de Oxybelis fulgidus', $result['summary']);
        $this->assertStringNotContainsString('Hapavirus', $result['summary']);
    }

    public function test_species_without_records_have_no_section(): void
    {
        $taxon = Taxa::create(['scientific_name' => 'Especie rara']);
        Http::fake(['api.globalbioticinteractions.org/*' => Http::response(['data' => []])]);

        $service = app(EcologicalImportanceService::class);
        $service->sync($taxon);

        $this->assertNull($service->present($taxon->load('apiReferences')));
    }
}
