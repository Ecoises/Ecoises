<?php

namespace App\Jobs;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Services\Api\EolService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrichSpeciesEcologyJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [60, 300, 900];
    public $timeout = 90;
    public $uniqueFor = 3600;

    public function __construct(public int $taxonId)
    {
        $this->onQueue('species-sync');
    }

    public function uniqueId(): string
    {
        return (string) $this->taxonId;
    }

    public function handle(EolService $eol): void
    {
        $taxon = Taxa::find($this->taxonId);

        if (!$taxon) {
            return;
        }

        $result = $eol->getEcologyProfile($taxon->scientific_name);

        if (!($result['success'] ?? false)) {
            Log::info('EOL no encontró un perfil ecológico', [
                'taxon_id' => $taxon->id,
                'scientific_name' => $taxon->scientific_name,
                'error' => $result['error'] ?? null,
            ]);
            return;
        }

        $profile = $result['data'];

        TaxonApiReference::updateOrCreate(
            [
                'taxon_id' => $taxon->id,
                'api_source' => 'eol',
                'external_id' => (string) $profile['eol_id'],
            ],
            [
                'api_url' => $profile['eol_url'],
                'confidence_score' => 1.0,
                'is_primary' => false,
                'last_verified_at' => now(),
                'data' => $profile,
            ]
        );
    }
}
