<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Validator;

$data = ['enrich' => 'true'];
$rules = ['enrich' => 'boolean'];

$validator = Validator::make($data, $rules);

if ($validator->fails()) {
    echo "Validation FAILED for 'true':\n";
    print_r($validator->errors()->all());
} else {
    echo "Validation PASSED for 'true'\n";
}

$data2 = ['enrich' => true];
$validator2 = Validator::make($data2, $rules);
if ($validator2->fails()) {
     echo "Validation FAILED for true (bool):\n";
} else {
     echo "Validation PASSED for true (bool)\n";
}
