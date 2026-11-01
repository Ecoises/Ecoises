<?php

namespace App\Console\Commands;

use App\Models\TaxonApiReference;
use App\Services\Biodiversity\EcologyTranslationService;
use Illuminate\Console\Command;

/**
 * Traduce al español los textos de EOL ya guardados que están en otro idioma.
 * Los perfiles nuevos se traducen solos en EnrichSpeciesEcologyJob.
 */
class TranslateEcologyTextsCommand extends Command
{
    protected $signature = 'ecology:translate {--limit=500 : Especies a traducir}';

    protected $description = 'Traduce al español los textos de hábitat, dieta y ecología (EOL) guardados en otro idioma';

    public function handle(EcologyTranslationService $translator): int
    {
        $translated = 0;
        $failed = 0;

        $references = TaxonApiReference::where('api_source', 'eol')->cursor()
            ->filter(fn (TaxonApiReference $reference) => $translator->pendingFields($reference->data ?? []) !== [])
            ->take((int) $this->option('limit'));

        foreach ($references as $reference) {
            $profile = $translator->translateProfile($reference->data);

            if ($translator->pendingFields($profile) === []) {
                $reference->update(['data' => $profile]);
                $translated++;
            } else {
                $failed++;
            }
        }

        $this->info("Traducidas {$translated} especies".($failed ? ", {$failed} sin traducir (se reintentan en la próxima ejecución)." : '.'));

        return self::SUCCESS;
    }
}
