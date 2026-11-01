<?php

namespace Tests\Feature;

use App\Jobs\GenerateEcologyTextJob;
use App\Models\GroupEcologyText;
use App\Models\SpeciesEcologyText;
use App\Models\Taxa;
use App\Services\Biodiversity\EcologicalImportanceService;
use App\Services\Biodiversity\EcologyTextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EcologyTextTest extends TestCase
{
    private const PLANT = 'Eukaryota | Plantae | Tracheophyta';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.gemini_key' => 'test-key', 'services.google.ecology_daily_limit' => 1]);

        Schema::create('taxa', function (Blueprint $table): void {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->string('common_name')->nullable();
            $table->string('kingdom')->nullable();
            $table->string('class')->nullable();
            $table->string('order_name')->nullable();
            $table->string('family')->nullable();
            $table->string('genus')->nullable();
            $table->boolean('is_native')->nullable();
            $table->boolean('is_endemic')->nullable();
            $table->boolean('is_introduced')->nullable();
            $table->string('conservation_status')->nullable();
            $table->integer('observation_count')->default(0);
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

        Schema::create('species_ecology_texts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('taxon_id')->unique();
            $table->text('text');
            $table->json('sources')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('published');
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('group_ecology_texts', function (Blueprint $table): void {
            $table->id();
            $table->string('rank');
            $table->string('name');
            $table->text('text');
            $table->json('sources')->nullable();
            $table->string('model')->nullable();
            $table->string('status')->default('published');
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->unique(['rank', 'name']);
        });
    }

    public function test_a_little_known_species_uses_its_genus_interactions(): void
    {
        $frog = Taxa::create(['scientific_name' => 'Pristimantis rarus', 'genus' => 'Pristimantis', 'family' => 'Strabomantidae']);

        Http::fake(function ($request) {
            $url = urldecode((string) $request->url());
            $isGenus = str_contains($url, 'sourceTaxon=Pristimantis&');

            return Http::response(['data' => $isGenus ? [
                ['eats', 'Drosophila melanogaster', 'Animalia | Arthropoda | Insecta'],
                ['eats', 'Musca domestica', 'Animalia | Arthropoda | Insecta'],
                ['eats', 'Aedes aegypti', 'Animalia | Arthropoda | Insecta'],
            ] : []]);
        });

        $service = app(EcologicalImportanceService::class);
        $service->sync($frog);
        $result = $service->present($frog->load('apiReferences'));

        $this->assertSame('genus', $result['scope']);
        $this->assertSame('Pristimantis', $result['scope_name']);
        $this->assertStringStartsWith('Las especies del género Pristimantis se alimentan de insectos', $result['summary']);
    }

    public function test_the_text_is_generated_once_from_verified_facts_and_respects_the_daily_limit(): void
    {
        $bird = Taxa::create(['scientific_name' => 'Ramphocelus carbo', 'common_name' => 'Fueguero oscuro', 'class' => 'Aves', 'family' => 'Thraupidae', 'genus' => 'Ramphocelus', 'is_native' => true]);
        $other = Taxa::create(['scientific_name' => 'Tangara gyrola', 'class' => 'Aves', 'family' => 'Thraupidae']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'El fueguero oscuro dispersa semillas.']]]]]]),
            '*' => Http::response(['data' => []]),
        ]);

        $texts = app(EcologyTextService::class);
        $this->assertTrue($texts->reserveDailySlot());
        $generated = $texts->generate($bird);

        $this->assertSame('El fueguero oscuro dispersa semillas.', $generated->text);
        $this->assertSame(SpeciesEcologyText::STATUS_PUBLISHED, $generated->status);
        $this->assertContains('GBIF', $generated->sources);
        $this->assertFalse($texts->needsText($bird));

        // El prompt solo lleva hechos verificados de la especie.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Usa SOLO los datos de la lista')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Familia: Thraupidae'));

        // Tope diario de 1: la segunda especie espera al día siguiente.
        $this->assertFalse($texts->reserveDailySlot());
        $this->assertTrue($texts->needsText($other));
    }

    public function test_redrafting_keeps_the_current_text_until_the_new_one_is_ready(): void
    {
        $bird = Taxa::create(['scientific_name' => 'Ramphocelus carbo', 'class' => 'Aves', 'family' => 'Thraupidae', 'genus' => 'Ramphocelus', 'is_native' => true]);
        SpeciesEcologyText::create([
            'taxon_id' => $bird->id,
            'text' => 'Texto revisado.',
            'status' => SpeciesEcologyText::STATUS_REVIEWED,
            'reviewed_by' => 1,
            'reviewed_at' => now(),
        ]);
        config(['services.google.ecology_daily_limit' => 5]);
        $redraft = fn () => app()->call([new GenerateEcologyTextJob($bird->id, regenerate: true), 'handle']);

        // Primer intento: la IA falla. Segundo: responde.
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push([], 500)
                ->push(['candidates' => [['content' => ['parts' => [['text' => 'Texto nuevo.']]]]]]),
            '*' => Http::response(['data' => []]),
        ]);

        // La IA falla: el texto anterior sigue intacto.
        $redraft();
        $this->assertSame('Texto revisado.', SpeciesEcologyText::where('taxon_id', $bird->id)->value('text'));

        // La IA responde: se reemplaza y vuelve a quedar sin revisar.
        $redraft();
        $text = SpeciesEcologyText::where('taxon_id', $bird->id)->first();
        $this->assertSame('Texto nuevo.', $text->text);
        $this->assertSame(SpeciesEcologyText::STATUS_PUBLISHED, $text->status);
        $this->assertNull($text->reviewed_by);
    }

    public function test_a_hidden_text_is_not_shown(): void
    {
        $taxon = Taxa::create(['scientific_name' => 'Ardea alba']);
        SpeciesEcologyText::create(['taxon_id' => $taxon->id, 'text' => 'Texto incorrecto', 'status' => SpeciesEcologyText::STATUS_REJECTED]);

        $this->assertNull(app(EcologyTextService::class)->textFor($taxon));
        $this->assertFalse(app(EcologyTextService::class)->needsText($taxon));
    }

    public function test_a_species_without_text_falls_back_to_its_genus_then_its_family(): void
    {
        $frog = Taxa::create(['scientific_name' => 'Pristimantis rarus', 'genus' => 'Pristimantis', 'family' => 'Strabomantidae']);
        $texts = app(EcologyTextService::class);

        $this->assertNull($texts->groupTextFor($frog));

        GroupEcologyText::create(['rank' => 'family', 'name' => 'Strabomantidae', 'text' => 'Texto de la familia']);
        $this->assertSame('family', $texts->groupTextFor($frog)->rank);

        GroupEcologyText::create(['rank' => 'genus', 'name' => 'Pristimantis', 'text' => 'Texto del género']);
        $this->assertSame('genus', $texts->groupTextFor($frog)->rank);
    }

    public function test_a_group_text_is_generated_from_its_species_and_uses_the_batch_quota(): void
    {
        config(['services.google.ecology_batch_daily_limit' => 1]);
        Taxa::create(['scientific_name' => 'Ramphocelus carbo', 'common_name' => 'Fueguero oscuro', 'kingdom' => 'Animalia', 'class' => 'Aves', 'family' => 'Thraupidae', 'genus' => 'Ramphocelus']);
        Taxa::create(['scientific_name' => 'Tangara gyrola', 'class' => 'Aves', 'family' => 'Thraupidae', 'genus' => 'Tangara']);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Las especies de esta familia dispersan semillas.']]]]]]),
            '*' => Http::response(['data' => []]),
        ]);

        $texts = app(EcologyTextService::class);
        // El cupo interactivo (1) se gasta sin tocar el de lotes.
        $this->assertTrue($texts->reserveDailySlot());
        $this->assertTrue($texts->reserveDailySlot(EcologyTextService::POOL_BATCH));
        $this->assertFalse($texts->reserveDailySlot(EcologyTextService::POOL_BATCH));

        $generated = $texts->generateGroup('family', 'Thraupidae');

        $this->assertSame('Las especies de esta familia dispersan semillas.', $generated->text);
        $this->assertFalse($texts->needsGroupText('family', 'Thraupidae'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'la familia Thraupidae')
            && str_contains($request['contents'][0]['parts'][0]['text'], 'Fueguero oscuro (Ramphocelus carbo)'));
    }
}
