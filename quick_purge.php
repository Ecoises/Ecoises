<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Eliminar duplicados no sincronizados
$deleted = DB::table('taxa')
    ->where('sync_status', '!=', 'synced')
    ->where(function($q) {
        $q->where('scientific_name', 'like', '%(%')
          ->orWhere('scientific_name', 'like', '% L.%')
          ->orWhere('scientific_name', 'like', '% Mill.%')
          ->orWhere('scientific_name', 'like', '% A.DC.%')
          ->orWhere('scientific_name', 'like', '% Kunth%')
          ->orWhere('scientific_name', 'like', '% Miers%')
          ->orWhere('scientific_name', 'like', '% Gould%');
    })
    ->delete();

// Limpiar tabla de trabajos fallidos
DB::table('failed_jobs')->truncate();

echo "Hecho! Eliminados {$deleted} registros huérfanos/duplicados no sincronizados de la base de datos.\n";
