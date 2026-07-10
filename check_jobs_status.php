<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Ver estado de jobs en la BD
echo "\n=== Estado de Jobs Enqueued ===\n\n";

$jobs = \DB::table('jobs')->count();
echo "Total jobs en queue: {$jobs}\n";

if ($jobs > 0) {
    $jobsList = \DB::table('jobs')->get();
    foreach ($jobsList as $job) {
        $payload = json_decode($job->payload, true);
        echo "\n📌 Job ID: {$job->id}\n";
        echo "   Queue: {$job->queue}\n";
        echo "   Command: " . substr($payload['displayName'], 0, 50) . "...\n";
        echo "   Created: {$job->created_at}\n";
    }
}

// Ver Taxa pendientes
echo "\n\n=== Estado de Taxa ===\n\n";
$totalTaxa = \App\Models\Taxa::count();
$synced = \App\Models\Taxa::where('sync_status', 'synced')->count();
$pending = \App\Models\Taxa::where('sync_status', 'pending')->count();
$syncing = \App\Models\Taxa::where('sync_status', 'syncing')->count();
$failed = \App\Models\Taxa::where('sync_status', 'failed')->count();

echo "Total Taxa: {$totalTaxa}\n";
echo "  ✓ Synced: {$synced}\n";
echo "  ⏳ Pending: {$pending}\n";
echo "  🔄 Syncing: {$syncing}\n";
echo "  ✗ Failed: {$failed}\n";

// Ver Taxa pendientes (las que se van a procesar)
if ($pending > 0) {
    echo "\nEjemplos de Taxa pending:\n";
    $pendingTaxa = \App\Models\Taxa::where('sync_status', 'pending')->limit(5)->pluck('scientific_name');
    foreach ($pendingTaxa as $taxon) {
        echo "  • {$taxon}\n";
    }
}

echo "\n✅ Verificación completada!\n";
