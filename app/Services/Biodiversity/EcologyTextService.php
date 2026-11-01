<?php

namespace App\Services\Biodiversity;

use App\Models\GroupEcologyText;
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
 *  - Hay dos topes diarios: uno para las fichas que abre una persona
 *    (ecology_daily_limit) y otro para el precálculo por lotes
 *    (ecology_batch_daily_limit), así los lotes nunca agotan el cupo interactivo.
 *  - Mientras la especie no tenga texto, la ficha muestra el de su género o
 *    familia (group_ecology_texts), marcado como del grupo.
 */
class EcologyTextService
{
    public const POOL_INTERACTIVE = 'interactive';

    public const POOL_BATCH = 'batch';

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

    /** Reserva un cupo del tope diario de ese grupo. Devuelve false si ya se alcanzó. */
    public function reserveDailySlot(string $pool = self::POOL_INTERACTIVE): bool
    {
        $key = $pool === self::POOL_BATCH
            ? 'ecology:ai-generations:batch:'.now()->format('Y-m-d')
            : 'ecology:ai-generations:'.now()->format('Y-m-d');
        Cache::add($key, 0, now()->endOfDay());

        if (Cache::increment($key) > $this->dailyLimit($pool)) {
            Cache::decrement($key);

            return false;
        }

        return true;
    }

    public function dailyLimit(string $pool = self::POOL_INTERACTIVE): int
    {
        return $pool === self::POOL_BATCH
            ? (int) config('services.google.ecology_batch_daily_limit', 300)
            : (int) config('services.google.ecology_daily_limit', 500);
    }

    /** Texto visible del género o, si no hay, de la familia de la especie. */
    public function groupTextFor(Taxa $taxon): ?GroupEcologyText
    {
        foreach ($this->groupsOf($taxon) as $rank => $name) {
            $text = GroupEcologyText::where('rank', $rank)->where('name', $name)->first();
            if ($text?->isVisible()) {
                return $text;
            }
        }

        return null;
    }

    /**
     * Género y familia de la especie (el género sale del nombre si falta).
     *
     * @return array<string, string>
     */
    public function groupsOf(Taxa $taxon): array
    {
        $genus = $taxon->genus ?: (str_contains((string) $taxon->scientific_name, ' ') ? strtok($taxon->scientific_name, ' ') : null);

        return array_filter(['genus' => $genus, 'family' => $taxon->family]);
    }

    public function needsGroupText(string $rank, string $name): bool
    {
        return ! GroupEcologyText::where('rank', $rank)->where('name', $name)->exists();
    }

    /**
     * Genera y guarda el texto de un género o una familia. Devuelve null si no
     * hay especies del grupo en la base, si la IA no respondió o si no hay clave.
     */
    public function generateGroup(string $rank, string $name): ?GroupEcologyText
    {
        $facts = $this->groupFacts($rank, $name);
        if ($facts['lines'] === []) {
            return null;
        }

        $result = $this->ask($this->groupPrompt($rank, $name, $facts['lines']));
        if (! $result) {
            Log::warning('Gemini no generó el texto ecológico del grupo', ['rank' => $rank, 'name' => $name]);

            return null;
        }

        return GroupEcologyText::updateOrCreate(
            ['rank' => $rank, 'name' => $name],
            [
                'text' => $result['text'],
                'sources' => $facts['sources'],
                'model' => $result['model'],
                'status' => SpeciesEcologyText::STATUS_PUBLISHED,
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Hechos del grupo: su taxonomía, las especies que tenemos de él, su resumen
     * de Wikipedia (si el grupo existe como taxón) y sus interacciones en GloBI.
     *
     * @return array{lines: array<int, string>, sources: array<int, string>}
     */
    public function groupFacts(string $rank, string $name): array
    {
        $column = $rank === 'genus' ? 'genus' : 'family';
        $members = Taxa::where($column, $name)->orderByDesc('observation_count')
            ->get(['scientific_name', 'common_name', 'kingdom', 'class', 'order_name', 'family']);
        if ($members->isEmpty()) {
            return ['lines' => [], 'sources' => []];
        }

        $lines = [];
        $sources = [];
        $first = $members->first();
        $taxonomy = collect(['Reino' => $first->kingdom, 'Clase' => $first->class, 'Orden' => $first->order_name, 'Familia' => $rank === 'genus' ? $first->family : null])
            ->filter()->map(fn ($value, $level) => "{$level}: {$value}")->implode(', ');
        if ($taxonomy) {
            $lines[] = "Taxonomía: {$taxonomy}.";
            $sources[] = 'GBIF';
        }

        $examples = $members->take(8)
            ->map(fn (Taxa $member) => $member->common_name ? "{$member->common_name} ({$member->scientific_name})" : $member->scientific_name)
            ->implode(', ');
        $lines[] = "Especies del grupo registradas en EcoIses ({$members->count()}): {$examples}.";

        $wikipedia = Taxa::with('apiReferences')->where('scientific_name', $name)->first()
            ?->apiReferences->firstWhere('api_source', 'inaturalist')?->data['wikipedia_summary'] ?? null;
        if ($wikipedia) {
            $lines[] = 'Resumen de Wikipedia: '.Str::limit(strip_tags($wikipedia), 700);
            $sources[] = 'Wikipedia';
        }

        $interactions = $this->ecology->presentGroup($rank, $name);
        if ($interactions) {
            $lines[] = "Interacciones registradas: {$interactions['summary']}";
            $sources[] = 'GloBI';
        }

        return ['lines' => $lines, 'sources' => array_values(array_unique($sources))];
    }

    /**
     * Genera y guarda el texto. Devuelve null si no hay hechos suficientes,
     * si la IA no respondió o si no hay clave configurada.
     */
    public function generate(Taxa $taxon): ?SpeciesEcologyText
    {
        $facts = $this->facts($taxon);
        if (count($facts['lines']) < 2) {
            return null; // con solo el nombre no hay nada verificable que contar
        }

        $result = $this->ask($this->prompt($taxon, $facts['lines']));
        if (! $result) {
            Log::warning('Gemini no generó el texto ecológico', ['taxon_id' => $taxon->id]);

            return null;
        }

        return SpeciesEcologyText::updateOrCreate(
            ['taxon_id' => $taxon->id],
            [
                'text' => $result['text'],
                'sources' => $facts['sources'],
                'model' => $result['model'],
                'status' => SpeciesEcologyText::STATUS_PUBLISHED,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'generated_at' => now(),
            ]
        );
    }

    /**
     * Pide el párrafo a Gemini. Devuelve null sin clave o si no respondió.
     *
     * @return array{text: string, model: string}|null
     */
    private function ask(string $prompt): ?array
    {
        $key = config('services.google.gemini_key');
        if (! $key) {
            return null;
        }

        $model = config('services.google.ecology_model', 'gemini-flash-lite-latest');
        $response = Http::timeout(40)->post(sprintf(self::ENDPOINT, $model).'?key='.$key, [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 400],
        ]);

        $text = trim((string) $response->json('candidates.0.content.parts.0.text'));
        if (! $response->successful() || $text === '') {
            Log::warning('Gemini no respondió', ['status' => $response->status()]);

            return null;
        }

        return ['text' => Str::limit(strip_tags($text), 900), 'model' => $model];
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

    private function groupPrompt(string $rank, string $name, array $lines): string
    {
        $group = $rank === 'genus' ? "el género {$name}" : "la familia {$name}";

        return implode("\n", [
            "Eres divulgador científico de biodiversidad colombiana. Escribe en español un párrafo de 60 a 100 palabras sobre la importancia ecológica de {$group}: qué papel cumplen sus especies en los ecosistemas y por qué vale la pena conservarlas.",
            'Reglas estrictas:',
            '- Habla del grupo en conjunto ("las especies de este género…"), nunca de una especie concreta.',
            '- Usa los datos de la lista. Puedes añadir solo conocimiento general ampliamente aceptado sobre el grupo, sin cifras, fechas ni lugares concretos.',
            '- Si no estás seguro de un papel, describe con prudencia lo que sí se sabe (tipo de organismo, hábitat general) sin especular.',
            '- Tono claro para estudiantes y público general. Sin títulos, listas, comillas ni emojis.',
            '',
            'Datos verificados:',
            ...array_map(fn ($line) => "- {$line}", $lines),
        ]);
    }
}
