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


// Listas nacionales del explorador listas antes de que llegue la primera persona del día.
Schedule::command('explorer:warm')->dailyAt('03:00')->withoutOverlapping();

// Precálculo de textos ecológicos por lotes pequeños mientras el servidor está
// encendido (en local lo corre `php artisan schedule:work`, incluido en `composer dev`).
// Primero los de género y familia, que cubren a muchas especies de una vez.
Schedule::command('ecology:groups --limit=10')->everyTenMinutes()->withoutOverlapping();
Schedule::command('ecology:generate --limit=10')->everyTenMinutes()->withoutOverlapping();

// Galerías de fotos de las especies que solo tienen la foto principal, por lotes.
Schedule::command('species:fill-galleries --limit=20')->everyTenMinutes()->withoutOverlapping();

// Taxonomía de las especies nuevas desde GBIF (Backbone Taxonomy).
Schedule::command('taxonomy:sync-gbif')->weeklyOn(0, '04:00')->withoutOverlapping();

// Listas oficiales de Colombia (Res. 0126/2024, endémicas, GRIIS) y UICN, una vez al mes.
Schedule::command('checklists:sync')->monthlyOn(1, '04:30')->withoutOverlapping();

// Notificaciones de la campana ya leídas con más de 90 días.
Schedule::call(fn () => \Illuminate\Notifications\DatabaseNotification::query()
    ->where('type', 'like', 'eco.%')
    ->whereNotNull('read_at')
    ->where('created_at', '<', now()->subDays(90))
    ->delete())->name('notifications:prune')->dailyAt('05:00');
