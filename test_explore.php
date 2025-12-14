<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);
// Initialized app

echo "Testing TaxonController::exploreColombiaSpecies...\n";

try {
    // Mock request
    $request = Illuminate\Http\Request::create('/api/taxa/explore/colombia', 'GET', [
        'page' => 1,
        'per_page' => 12,
        // 'native' => 'false', 
        // 'endemic' => 'false',
        // 'threatened' => 'false'
    ]);
    
    // Resolve controller
    $controller = app(App\Http\Controllers\Api\TaxonController::class);
    
    // Call method
    $response = $controller->exploreColombiaSpecies($request);
    
    echo "Status: " . $response->status() . "\n";
    if ($response->status() != 200) {
        echo "Content: " . $response->getContent() . "\n";
    } else {
        $json = json_decode($response->getContent(), true);
        echo "Meta: " . json_encode($json['meta'], JSON_PRETTY_PRINT) . "\n";
        echo "First 3 species:\n";
        foreach (array_slice($json['data'], 0, 3) as $spec) {
             echo "- " . ($spec['scientific_name'] ?? 'N/A') . " (Obs: " . ($spec['observations_count'] ?? 0) . ")\n";
        }
    }

} catch (\Illuminate\Validation\ValidationException $e) {
    echo "Validation Error: " . json_encode($e->errors(), JSON_PRETTY_PRINT) . "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
