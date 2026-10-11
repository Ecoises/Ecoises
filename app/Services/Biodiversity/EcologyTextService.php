<?php

namespace App\Services\Biodiversity;

use App\Models\SpeciesEcologyText;
use App\Models\Taxa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Redacta con IA (Gemini) un párrafo de importancia ecológica por especie.
 *
 * Reglas para que sea confiable y barato:
 *  - Se genera UNA vez por especie y se guarda en species_ecology_texts.
 *    Las fichas lo leen de la base de datos: el número de usuarios no cambia
 *    cuántas llamadas se hacen a la IA.
 *  - A la IA solo se le entregan hechos ya verificados (taxonomía, GBIF, EOL,
 *    Wikipedia y las interacciones de GloBI). Se le pide no añadir nada más.
 *  - Hay un tope diario de generaciones (services.google.ecology_daily_limit).
 */
class EcologyTextService
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    private const IUCN = [
        'LC' => 'preocupación menor', 'NT' => 'casi amenazada', 'VU' => 'vulnerable', 'EN' => 'en peligro',
        'CR' => 'en peligro crítico', 'EW' => 'extinta en estado silvestre', 'EX' => 'extinta', 'DD' => 'datos insuficientes',
    ];

    public function __construct(private readonly EcologicalImportanceService $ecology) {}

    public function textFor(Taxa $taxon): ?SpeciesEcologyText
    {
        $text = SpeciesEcologyText::where('taxon_id', $taxon->id)->first();

        return $text?->isVisible() ? $text : null;
    }

    public function needsText(Taxa $taxon): bool
    {
        return ! SpeciesEcologyText::where('taxon_id', $taxon->id)->exists();
    }

    /** Reserva un cupo del tope diario. Devuelve false si ya se alcanzó. */
    public function reserveDailySlot(): bool
    {
        $key = 'ecology:ai-generations:'.now()->format('Y-m-d');
        Cache::add($key, 0, now()->endOfDay());

        if (Cache::increment($key) > $this->dailyLimit()) {
            Cache::decrement($key);

            return false;
        }

        return true;
    }

    public function dailyLimit(): int
    {
        return (int) config('services.google.ecology_daily_limit', 500);
    }

    /**
     * Genera y guarda el texto. Devuelve null si no hay hechos suficientes,
     * si la IA no respondió o si no hay clave configurada.
     */
    public function generate(Taxa $taxon): ?SpeciesEcologyText
    {
        $key = config('services.google.gemini_key');
        if (! $key) {
            return null;
        }

        $facts = $this->facts($taxon);
        if (count($facts['lines']) < 2) {
            return null; // con solo el nombre no hay nada verificable que contar
        }

        $model = config('services.google.ecology_model', 'gemini-flash-lite-latest');
        $response = Http::timeout(40)->post(sprintf(self::ENDPOINT, $model).'?key='.$key, [
            'contents' => [['parts' => [['text' => $this->prompt($taxon, $facts['lines'])]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 400],
        ]);

        $text = trim((string) $response->json('candidates.0.content.parts.0.text'));
        if (! $response->successful() || $text === '') {
            Log::warning('Gemini no generó el texto ecológico', ['taxon_id' => $taxon->id, 'status' => $response->status()]);

            return null;
        }

        return SpeciesEcologyText::updateOrCreate(
            ['taxon_id' => $taxon->id],
            [
                'text' => Str::limit(strip_tags($text), 900),
                'sources' => $facts['sources'],
                'model' => $model,
                'status' => SpeciesEcologyText::STATUS_PUBLISHED,
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Hechos verificados de la especie, cada uno con su fuente.
     *
     * @return array{lines: array<int, string>, sources: array<int, string>}
     */
    public function facts(Taxa $taxon): array
    {
        $taxon->loadMissing('apiReferences');
        $lines = [];
        $sources = [];

        $taxonomy = collect(['Reino' => $taxon->kingdom, 'Clase' => $taxon->class, 'Orden' => $taxon->order_name, 'Familia' => $taxon->family, 'Género' => $taxon->genus])
            ->filter()->map(fn ($value, $rank) => "{$rank}: {$value}")->implode(', ');
        if ($taxonomy) {
            $lines[] = "Taxonomía: {$taxonomy}.";
            $sources[] = 'GBIF';
        }

        $status = collect([
            $taxon->is_endemic ? 'endémica de Colombia' : null,
            ! $taxon->is_endemic && $taxon->is_native ? 'nativa de Colombia' : null,
            $taxon->is_introduced ? 'introducida en Colombia' : null,
            $taxon->conservation_status ? 'categoría UICN: '.(self::IUCN[$taxon->conservation_status] ?? $taxon->conservation_status) : null,
        ])->filter()->implode(', ');
        if ($status) {
            $lines[] = "Estatus: {$status}.";
        }

        $wikipedia = $taxon->apiReferences->firstWhere('api_source', 'inaturalist')?->data['wikipedia_summary'] ?? null;
        if ($wikipedia) {
            $lines[] = 'Resumen de Wikipedia: '.Str::limit(strip_tags($wikipedia), 700);
            $sources[] = 'Wikipedia';
        }

        $eol = $taxon->apiReferences->firstWhere('api_source', 'eol')?->data ?? [];
        foreach (['habitat' => 'Hábitat', 'diet' => 'Alimentación', 'ecology' => 'Ecología'] as $field => $label) {
            if (! empty($eol[$field]['text'])) {
                $lines[] = "{$label} (EOL): ".Str::limit(strip_tags($eol[$field]['text']), 500);
                $sources[] = 'EOL';
            }
        }

        $interactions = $this->ecology->present($taxon);
        if ($interactions) {
            $scope = match ($interactions['scope']) {
                'genus' => " (datos de su género {$interactions['scope_name']}, no de la especie)",
                'family' => " (datos de su familia {$interactions['scope_name']}, no de la especie)",
                default => '',
            };
            $lines[] = "Interacciones registradas{$scope}: {$interactions['summary']}";
            $sources[] = 'GloBI';
        }

        return ['lines' => $lines, 'sources' => array_values(array_unique($sources))];
    }

    private function prompt(Taxa $taxon, array $lines): string
    {
        $name = $taxon->common_name ? "{$taxon->common_name} ({$taxon->scientific_name})" : $taxon->scientific_name;

        return implode("\n", [
            "Eres divulgador científico de biodiversidad colombiana. Escribe en español un párrafo de 60 a 100 palabras sobre la importancia ecológica de {$name}: qué papel cumple en su ecosistema y por qué vale la pena conservarla.",
            'Reglas estrictas:',
            '- Usa SOLO los datos de la lista. No inventes cifras, lugares, comportamientos ni relaciones que no estén ahí.',
            '- Si un dato es del género o la familia, dilo así ("como otras especies de su género…"), sin atribuirlo a la especie.',
            '- Si los datos no alcanzan para afirmar un papel concreto, describe con prudencia lo que sí se sabe (grupo, hábitat) sin especular.',
            '- Tono claro para estudiantes y público general. Sin títulos, listas, comillas ni emojis.',
            '',
            'Datos verificados:',
            ...array_map(fn ($line) => "- {$line}", $lines),
        ]);
    }
}
