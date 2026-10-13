<?php

namespace Tests\Feature;

use App\Jobs\EnrichSpeciesJob;
use App\Models\Taxa;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CleanScientificNamesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('taxa', function (Blueprint $table): void {
            $table->id();
            $table->string('scientific_name')->unique();
            $table->string('common_name')->nullable();
            $table->string('taxon_author')->nullable();
            $table->string('gbif_taxon_key')->nullable();
            $table->string('inat_taxon_id')->nullable();
            $table->string('sync_status')->nullable();
            $table->timestamps();
        });

        foreach (['observations', 'challenges', 'species_ecology_texts', 'unified_api_caches'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('taxon_id')->nullable();
            });
        }

        Schema::create('taxon_api_references', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('taxon_id');
            $table->string('api_source');
        });

        Queue::fake();
    }

    public function test_new_names_are_saved_without_the_author(): void
    {
        $taxon = Taxa::create(['scientific_name' => 'Quiscalus lugubris Swainson, 1838']);

        $this->assertSame('Quiscalus lugubris', $taxon->scientific_name);
        $this->assertSame('Swainson, 1838', $taxon->taxon_author);
    }

    public function test_it_merges_the_authored_duplicate_into_the_clean_species(): void
    {
        $clean = Taxa::create(['scientific_name' => 'Quiscalus lugubris', 'inat_taxon_id' => '9851']);
        $duplicate = DB::table('taxa')->insertGetId(['scientific_name' => 'Quiscalus lugubris Swainson, 1838', 'gbif_taxon_key' => '2484159']);
        DB::table('observations')->insert(['taxon_id' => $duplicate]);
        DB::table('taxon_api_references')->insert([
            ['taxon_id' => $clean->id, 'api_source' => 'inaturalist'],
            ['taxon_id' => $duplicate, 'api_source' => 'inaturalist'],
            ['taxon_id' => $duplicate, 'api_source' => 'gbif'],
        ]);

        $this->artisan('taxa:clean-names')->assertSuccessful();

        $this->assertDatabaseMissing('taxa', ['id' => $duplicate]);
        $clean->refresh();
        $this->assertSame('2484159', $clean->gbif_taxon_key);
        $this->assertSame('Swainson, 1838', $clean->taxon_author);
        $this->assertSame(1, DB::table('observations')->where('taxon_id', $clean->id)->count());
        $this->assertSame(['gbif', 'inaturalist'], DB::table('taxon_api_references')->where('taxon_id', $clean->id)->orderBy('api_source')->pluck('api_source')->all());
        Queue::assertNothingPushed();
    }

    public function test_it_renames_when_there_is_no_clean_species_and_fetches_its_photo(): void
    {
        $id = DB::table('taxa')->insertGetId(['scientific_name' => 'Anolis auratus Daudin, 1802']);

        $this->artisan('taxa:clean-names')->assertSuccessful();

        $this->assertSame('Anolis auratus', Taxa::find($id)->scientific_name);
        Queue::assertPushed(EnrichSpeciesJob::class);
    }

    public function test_dry_run_changes_nothing(): void
    {
        DB::table('taxa')->insert(['scientific_name' => 'Anolis auratus Daudin, 1802']);

        $this->artisan('taxa:clean-names', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('taxa', ['scientific_name' => 'Anolis auratus Daudin, 1802']);
    }
}
