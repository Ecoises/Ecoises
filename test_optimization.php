<?php

use App\Services\TaxonService;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$service = app(TaxonService::class);

echo "--- TESTING OPTIMIZATIONS ---\n";

// 1. Global List (Search empty)
try {
    $list = $service->searchTaxa('', ['per_page' => 1]);
    $first = $list['data'][0] ?? null;
    echo "Global List - Success: " . ($list['success'] ? 'YES' : 'NO') . "\n";
    echo "Global List - Has Default Photo: " . (isset($first['default_photo']) ? 'YES' : 'NO') . "\n";
    echo "Global List - Has Gallery (Should be NO): " . (isset($first['gallery']) ? 'YES' : 'NO') . "\n";
} catch (\Exception $e) {
    echo "Global List - Error: " . $e->getMessage() . "\n";
}

// 2. Detail View (Get ID - 21302)
try {
    // 21302
    $detail = $service->getTaxonById(21302, true); // Force refresh to test API
    $data = $detail['data']->enriched_data ?? $detail['data'];
    echo "Detail - Success: " . ($detail['success'] ? 'YES' : 'NO') . "\n";
    echo "Detail - Name: " . ($data['scientific_name'] ?? $data['name']) . "\n";
    echo "Detail - Has Gallery: " . (isset($data['gallery']) ? 'YES' : 'NO') . "\n";
    echo "Detail - Gallery Count: " . count($data['gallery'] ?? []) . "\n";
    if (isset($data['gallery'][0])) {
         echo "Detail - Gallery[0] has attribution: " . (isset($data['gallery'][0]['attribution']) ? 'YES' : 'NO') . "\n";
    }
} catch (\Exception $e) {
    echo "Detail - Error: " . $e->getMessage() . "\n";
}

// 3. Related Species
try {
    $related = $service->getRelatedSpecies(415528);
    echo "Related - Success: " . ($related['success'] ? 'YES' : 'NO') . "\n";
    echo "Related - Count: " . count($related['data']) . "\n";
    $firstRelated = $related['data'][0] ?? null;
    if ($firstRelated) {
        echo "Related - First Name: " . ($firstRelated['scientific_name'] ?? $firstRelated['name']) . "\n";
        echo "Related - Has Photo: " . (isset($firstRelated['default_photo']) ? 'YES' : 'NO') . "\n";
    }
} catch (\Exception $e) {
    echo "Related - Error: " . $e->getMessage() . "\n";
}
