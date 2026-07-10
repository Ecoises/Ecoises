<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ========== Scheduling para Pipeline de Sincronización ==========

/**
 * Sincronizar especies desactualizadas diariamente a las 2 AM.
 * 
 * Esto re-enriquece especies cuya última sincronización fue hace >30 días,
 * o nunca fueron sincronizadas. Los jobs se encolan en la cola 'species-sync'
 * y se procesan con: php artisan queue:work --queue=species-sync
 */
Schedule::command('species:sync-stale')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onSuccess(function () {
        \Illuminate\Support\Facades\Log::info('✅ SyncStaleSpeciesCommand completado exitosamente');
    })
    ->onFailure(function (\Throwable $exception) {
        \Illuminate\Support\Facades\Log::error('❌ SyncStaleSpeciesCommand falló', [
            'error' => $exception->getMessage(),
        ]);
    });

