<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$a = \App\Models\Activity::where('activity_type', 'quiz_true_false')->first();
echo json_encode($a, JSON_PRETTY_PRINT);
