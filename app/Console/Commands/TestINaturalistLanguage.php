<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TaxonService;

class TestINaturalistLanguage extends Command
{
    protected $signature = 'test:inat-lang {query? : Search query} {--id= : Taxon ID to look up}';
    protected $description = 'Test iNaturalist API language settings';

    protected $taxonService;

    public function __construct(TaxonService $taxonService)
    {
        parent::__construct();
        $this->taxonService = $taxonService;
    }

    public function handle()
    {
        if ($this->option('id')) {
            return $this->testTaxonById($this->option('id'));
        }

        $query = $this->argument('query') ?? 'jaguar';
        $this->testTaxonSearch($query);
    }

    protected function testTaxonSearch($query)
    {
        $this->info("Searching for: {$query}");
        
        $result = $this->taxonService->searchTaxa($query, [
            'per_page' => 3,
            'rank' => 'species'
        ]);

        if (!$result['success']) {
            $this->error("Error: " . ($result['error']['message'] ?? 'Unknown error'));
            return 1;
        }

        $this->info("\nSearch results (Source: {$result['source']}):");
        
        foreach ($result['data'] as $taxon) {
            $this->line("\n<fg=green>" . ($taxon['scientific_name'] ?? 'Unknown') . "</>");
            $this->line("Common name: " . ($taxon['common_name'] ?? 'N/A'));
            $this->line("Rank: " . ($taxon['rank'] ?? 'N/A'));
            
            if (isset($taxon['default_photo']['url'])) {
                $this->line("Photo: " . $taxon['default_photo']['url']);
            }
        }

        return 0;
    }

    protected function testTaxonById($id)
    {
        $this->info("Fetching taxon ID: {$id}");
        
        $result = $this->taxonService->getTaxonById($id);

        if (!$result['success']) {
            $this->error("Error: " . ($result['error']['message'] ?? 'Unknown error'));
            return 1;
        }

        $taxon = $result['data'];
        
        $this->info("\nTaxon details (Source: {$result['source']}):");
        $this->line("\n<fg=green>" . ($taxon['scientific_name'] ?? 'Unknown') . "</>");
        $this->line("Common name: " . ($taxon['common_name'] ?? 'N/A'));
        $this->line("Rank: " . ($taxon['rank'] ?? 'N/A'));
        $this->line("Status: " . ($taxon['extinct'] ? 'Extinct' : 'Extant'));
        
        if (isset($taxon['wikipedia_summary'])) {
            $this->line("\nSummary:");
            $this->line(wordwrap($taxon['wikipedia_summary'], 80));
        }

        return 0;
    }
}
