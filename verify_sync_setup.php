<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Verificar que los campos existen en Taxa
$columns = Schema::getColumns('taxa');
echo "\n=== Verificación de Campos en Tabla 'taxa' ===\n\n";

$newFields = ['last_synced_at', 'sync_status', 'sync_attempts', 'gbif_taxon_key', 'inat_taxon_id'];
$found = [];
$notFound = [];

foreach ($columns as $col) {
    if (in_array($col['name'], $newFields)) {
        $found[] = $col['name'];
    }
}

$notFound = array_diff($newFields, $found);

echo "✅ Campos encontrados:\n";
foreach ($found as $field) {
    echo "  ✓ {$field}\n";
}

if (!empty($notFound)) {
    echo "\n❌ Campos NO encontrados:\n";
    foreach ($notFound as $field) {
        echo "  ✗ {$field}\n";
    }
} else {
    echo "\n✅ TODOS los campos de sincronización se crearon correctamente!\n";
}

// Contar registros en Taxa
$totalTaxa = \App\Models\Taxa::count();
echo "\n📊 Estadísticas:\n";
echo "  Total de Taxa en BD: {$totalTaxa}\n";

$synced = \App\Models\Taxa::where('sync_status', 'synced')->count();
$pending = \App\Models\Taxa::where('sync_status', 'pending')->count();

echo "  - Synced: {$synced}\n";
echo "  - Pending: {$pending}\n";

echo "\n✅ Base de datos verificada correctamente!\n";
