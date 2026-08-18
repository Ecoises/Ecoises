<?php

namespace App\Jobs;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Services\SpeciesMerger;
use App\Services\Api\GbifService;
use App\Services\Api\INaturalistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use Illuminate\Support\Facades\Log;
use Illuminate\Database\UniqueConstraintViolationException;

class EnrichSpeciesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 120, 600]; // 30s, 2min, 10min
    public $timeout = 120;
    public $uniqueFor = 3600;

    /**
     * Nombre científico ya normalizado (canonical)
     * 
     * @var string
     */
    protected string $canonicalName;

    /**
     * Create a new job instance.
     */
    public function __construct(string $canonicalName)
    {
        $this->canonicalName = $canonicalName;
        $this->onQueue('species-sync');
    }

    public function uniqueId(): string
    {
        return mb_strtolower(SpeciesMerger::stripAuthorship($this->canonicalName));
    }
    // Sin middleware: cola species-sync tiene 1 solo worker, no hay race conditions

    /**
     * Execute the job.
     */
    public function handle(GbifService $gbif, INaturalistService $inat, SpeciesMerger $merger): void
    {
        $cleanName = SpeciesMerger::stripAuthorship($this->canonicalName);

        Log::info("🔄 Enriqueciendo especie: {$cleanName} (original: {$this->canonicalName})");

        // Buscar por nombre original o limpio; crear si no existe
        $taxon = Taxa::where('scientific_name', $this->canonicalName)
            ->orWhere('scientific_name', $cleanName)
            ->first();

        if (!$taxon) {
            try {
                $taxon = Taxa::create([
                    'scientific_name' => $cleanName,
                    'sync_status' => 'pending',
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $taxon = Taxa::where('scientific_name', $cleanName)->firstOrFail();
            }
        } elseif ($taxon->scientific_name !== $cleanName) {
            $taxon->update(['scientific_name' => $cleanName]);
        }

        // Marcar como "syncing"
        $taxon->update([
            'sync_status' => 'syncing',
        ]);

        try {
            // 1. Obtener datos de ambas fuentes en paralelo
            $gbifData = $gbif->searchTaxon($cleanName);
            $inatData = $inat->searchTaxon($cleanName);

            // 2. Mergear y normalizar
            $mergedData = $merger->merge($gbifData, $inatData, $cleanName);

            if (!$mergedData['success']) {
                throw new \Exception('Merge falló: ' . ($mergedData['error'] ?? 'Desconocido'));
            }

            $canonical = $mergedData['data'];
            $colombianCommonName = null;
            if (!empty($canonical['gbifTaxonKey'])) {
                $colombianCommonName = $gbif
                    ->getColombianVernacularNamesBatch([$canonical['gbifTaxonKey']])
                    [(string) $canonical['gbifTaxonKey']] ?? null;
            }

            $resolvedName = $canonical['scientificName'] ?? $cleanName;

            // Si GBIF devolvió un taxón superior (reino/filo/clase), mantener el nombre original
            // para evitar conflictos unique key con nombres como "Animalia" o "Tracheophyta"
            $isHigherTaxon = ($canonical['rank'] ?? null) === null
                || in_array($canonical['rank'] ?? '', ['kingdom', 'phylum', 'class', 'order', 'family', 'genus']);
            $finalName = $isHigherTaxon ? $cleanName : $resolvedName;

            // 3. Actualizar Taxa con datos normalizados
            $taxon->update([
                'scientific_name' => $finalName,
                'common_name' => $colombianCommonName
                    ?: ($taxon->common_name ?: ($canonical['commonName'] ?? null)),
                'kingdom' => $canonical['kingdom'] ?? null,
                'phylum' => $canonical['phylum'] ?? null,
                'class' => $canonical['class'] ?? null,
                'order_name' => $canonical['order'] ?? null,
                'family' => $canonical['family'] ?? null,
                'genus' => $canonical['genus'] ?? null,
                'species' => $canonical['species'] ?? null,
                'conservation_status' => $canonical['conservationStatus'] ?? null,
                'is_native' => $canonical['isNative'] ?? null,
                'is_endemic' => $canonical['isEndemic'] ?? null,
                'is_introduced' => $canonical['isIntroduced'] ?? null,
                'gbif_taxon_key' => $canonical['gbifTaxonKey'] ?? null,
                'inat_taxon_id' => $canonical['inatTaxonId'] ?? null,
                'sync_status' => 'synced',
                'sync_attempts' => 0,
                'last_synced_at' => now(),
            ]);

            // 4. Guardar referencias a APIs externas
            $this->storeApiReferences($taxon, $canonical);

            Log::info("✅ Especie enriquecida exitosamente: {$cleanName}");

        } catch (\Throwable $e) {
            try {
                $taxon = $taxon->fresh() ?? Taxa::where('scientific_name', $cleanName)->first();
                if ($taxon) {
                    $taxon->increment('sync_attempts');
                    $final = $taxon->sync_attempts >= $this->tries;
                    $taxon->timestamps = false;
                    $taxon->update(['sync_status' => $final ? 'failed' : 'pending']);
                    $taxon->timestamps = true;
                    if ($final) {
                        Log::error("❌ Falló {$cleanName} tras {$this->tries} intentos: {$e->getMessage()}");
                    } else {
                        Log::warning("⚠ Reintentando {$cleanName} (intento {$taxon->sync_attempts}/{$this->tries}): {$e->getMessage()}");
                    }
                }
            } catch (\Throwable $inner) {
                Log::error("Error al actualizar estado de {$cleanName}", ['error' => $inner->getMessage()]);
            }
            throw $e;
        }
    }

    /**
     * Almacena referencias a las APIs en TaxonApiReference
     * 
     * @param Taxa $taxon
     * @param array $canonical
     */
    protected function storeApiReferences(Taxa $taxon, array $canonical): void
    {
        // GBIF
        if ($canonical['gbifData'] ?? null) {
            TaxonApiReference::updateOrCreate(
                ['taxon_id' => $taxon->id, 'api_source' => 'gbif'],
                [
                    'external_id' => $canonical['gbifTaxonKey'],
                    'api_url' => "https://www.gbif.org/species/{$canonical['gbifTaxonKey']}",
                    'confidence_score' => $canonical['gbifConfidence'] ?? 1.0,
                    'is_primary' => true,
                    'last_verified_at' => now(),
                    'data' => $canonical['gbifData'],
                ]
            );
        }

        // iNaturalist
        if ($canonical['inatData'] ?? null) {
            $existing = $taxon->apiReferences()
                ->where('api_source', 'inaturalist')
                ->first();
            $incomingData = $canonical['inatData'];
            $existingData = $existing?->data ?? [];
            $existingIsRich = !empty($existingData['gallery'])
                || !empty($existingData['taxon_photos'])
                || !empty($existingData['wikipedia_summary']);
            $incomingIsRich = !empty($incomingData['gallery'])
                || !empty($incomingData['taxon_photos'])
                || !empty($incomingData['wikipedia_summary']);

            TaxonApiReference::updateOrCreate(
                ['taxon_id' => $taxon->id, 'api_source' => 'inaturalist'],
                [
                    'external_id' => $canonical['inatTaxonId'],
                    'api_url' => "https://www.inaturalist.org/taxa/{$canonical['inatTaxonId']}",
                    'confidence_score' => $canonical['inatConfidence'] ?? 1.0,
                    'is_primary' => false,
                    'last_verified_at' => now(),
                    'data' => $existingIsRich && !$incomingIsRich ? $existingData : $incomingData,
                ]
            );
        }

        EnrichSpeciesEcologyJob::dispatch($taxon->id)->delay(now()->addSeconds(2));
    }
}
