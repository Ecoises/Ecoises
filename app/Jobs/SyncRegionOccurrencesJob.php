<?php

namespace App\Jobs;

use App\Models\Taxa;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\Api\GbifService;

class SyncRegionOccurrencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $backoff = [60, 300]; // 1min, 5min
    public $timeout = 60;

    protected float $latitude;
    protected float $longitude;
    protected float $radiusKm;

    /**
     * Create a new job instance.
     */
    public function __construct(float $latitude, float $longitude, float $radiusKm = 50)
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->radiusKm = $radiusKm;
        $this->onQueue('species-sync');
    }

    /**
     * Execute the job.
     * 
     * Indexa qué especies de nuestro catálogo nacional tienen ocurrencias en esta región.
     * No enriquece especies nuevas — solo mapea geografía.
     */
    public function handle(GbifService $gbif): void
    {
        Log::info("🗺️ Sincronizando ocurrencias para zona", [
            'lat' => $this->latitude,
            'lon' => $this->longitude,
            'radius_km' => $this->radiusKm,
        ]);

        try {
            // Obtener especies que GBIF reporta en esta zona
            $response = $gbif->searchOccurrencesByRegion(
                $this->latitude,
                $this->longitude,
                $this->radiusKm
            );

            if (!$response['success']) {
                throw new \Exception('GBIF error: ' . ($response['error']['message'] ?? 'Desconocido'));
            }

            $speciesFromGbif = $response['data'] ?? [];

            if (empty($speciesFromGbif)) {
                Log::info("ℹ️ No hay especies en esta zona según GBIF");
                return;
            }

            // Intentar matchear con nuestro catálogo nacional
            $matched = 0;
            foreach ($speciesFromGbif as $gbifSpecies) {
                $scientificName = $gbifSpecies['scientificName'] ?? $gbifSpecies['canonical_name'] ?? null;

                if (!$scientificName) {
                    continue;
                }

                // Buscar por nombre exacto o GBIF key si tenemos
                $taxon = Taxa::where('scientific_name', $scientificName)
                    ->orWhere('gbif_taxon_key', $gbifSpecies['gbif_key'] ?? null)
                    ->where('sync_status', 'synced')
                    ->first();

                if ($taxon) {
                    // La especie ya está catalogada — solo registramos que está en esta zona
                    // (Esto asume que tienes un modelo Occurrence con geolocalización)
                    // $taxon->occurrences()->updateOrCreate(
                    //     ['latitude' => $this->latitude, 'longitude' => $this->longitude],
                    //     ['region_radius_km' => $this->radiusKm, 'confirmed_at' => now()]
                    // );
                    $matched++;
                } else {
                    // Especie no catalogada en nuestra BD — enqueue para enriquecimiento
                    // (Esto es raro post-backfill, pero maneja excepciones)
                    EnrichSpeciesJob::dispatch($scientificName)
                        ->onQueue('species-sync');
                }
            }

            Log::info("✅ Zona sincronizada", [
                'matched' => $matched,
                'total_from_gbif' => count($speciesFromGbif),
            ]);

        } catch (\Throwable $e) {
            Log::error("❌ Error sincronizando zona", [
                'error' => $e->getMessage(),
                'lat' => $this->latitude,
                'lon' => $this->longitude,
            ]);
            throw $e;
        }
    }
}
