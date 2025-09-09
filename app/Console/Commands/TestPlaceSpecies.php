<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestPlaceSpecies extends Command
{
    protected $signature = 'test:place-species {--per_page=10} {--page=1} {--order_by=created_at} {--order=desc}';
    protected $description = 'Test the place species endpoint with place_id=12731';

    public function handle()
    {
        $baseUrl = env('APP_URL') . '/api';
        $params = [
            'per_page' => $this->option('per_page'),
            'page' => $this->option('page'),
            'order_by' => $this->option('order_by'),
            'order' => $this->option('order'),
        ];

        $url = "{$baseUrl}/taxa/place/species?" . http_build_query($params);
        
        $this->info("Testing endpoint: {$url}");
        
        try {
            $response = Http::get($url);
            $data = $response->json();
            
            if ($response->successful() && $data['success']) {
                $this->info("\nSuccess! Found " . count($data['data']) . " species.");
                $this->info("Source: " . ($data['meta']['source'] ?? 'N/A'));
                $this->info("Cached: " . ($data['meta']['cached'] ? 'Yes' : 'No'));
                
                $this->info("\nFirst 5 species:");
                $this->table(
                    ['ID', 'Scientific Name', 'Common Name', 'Rank', 'Observations'],
                    collect($data['data'])->take(5)->map(function($species) {
                        return [
                            'id' => $species['id'],
                            'scientific_name' => $species['scientific_name'],
                            'common_name' => $species['common_name'] ?? 'N/A',
                            'rank' => $species['rank'],
                            'observations_count' => $species['observations_count'] ?? 0,
                        ];
                    })
                );
                
                if (isset($data['meta']['pagination'])) {
                    $this->info("\nPagination:");
                    $this->table(
                        ['Page', 'Per Page', 'Total'],
                        [[
                            'current_page' => $data['meta']['pagination']['current_page'] ?? 1,
                            'per_page' => $data['meta']['pagination']['per_page'] ?? count($data['data']),
                            'total' => $data['meta']['pagination']['total'] ?? count($data['data']),
                        ]]
                    );
                }
                
                return 0;
            } else {
                $this->error("Error: " . ($data['message'] ?? 'Unknown error'));
                if (isset($data['error'])) {
                    $this->error(print_r($data['error'], true));
                }
                return 1;
            }
        } catch (\Exception $e) {
            $this->error("Exception: " . $e->getMessage());
            return 1;
        }
    }
}
