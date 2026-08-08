<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Taxa;
$taxa = Taxa::where('scientific_name', 'like', '%Opuntia cochenillifera%')->get();
foreach ($taxa as $t) {
    echo "ID: {$t->id} | Name: '{$t->scientific_name}' | Status: {$t->sync_status} | Created: {$t->created_at}\n";
}
