<?php

namespace App\Services\Biodiversity;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Services\ExternalApi\GlobiService;
use Illuminate\Support\Collection;

/**
 * Importancia ecológica propia de cada especie, construida a partir de las
 * interacciones registradas en GloBI (qué come, qué poliniza, de qué plantas
 * dispersa semillas, quién la depreda…).
 *
 * 1. sync()    → descarga y resume las interacciones (se ejecuta en la cola).
 * 2. present() → convierte ese resumen en roles y un texto en español,
 *                enlazando las especies relacionadas que ya están en el catálogo.
 *
 * No se inventa nada: cada frase sale de registros publicados y se cita la fuente.
 */
class EcologicalImportanceService
{
    /** Roles en el orden en que se muestran (los más "impactantes" primero). */
    public const ROLES = [
        'seed_disperser' => ['label' => 'Dispersor de semillas', 'verb' => 'dispersa las semillas de'],
        'pollinator' => ['label' => 'Polinizador', 'verb' => 'poliniza o visita las flores de'],
        'insect_control' => ['label' => 'Controlador de insectos', 'verb' => 'se alimenta de insectos y otros artrópodos como'],
        'predator' => ['label' => 'Depredador', 'verb' => 'regula las poblaciones de'],
        'plant_feeder' => ['label' => 'Consumidor de frutos y plantas', 'verb' => 'se alimenta de'],
        'dispersed_by' => ['label' => 'Sus semillas viajan con otros', 'verb' => 'depende de animales que dispersan sus semillas, como'],
        'pollinated_by' => ['label' => 'Alimenta a polinizadores', 'verb' => 'es polinizada o visitada por'],
        'food_source' => ['label' => 'Alimento de otras especies', 'verb' => 'es alimento de'],
    ];

    private const MAX_PARTNERS_STORED = 25;

    /**
     * Registros mínimos para afirmar un rol. Dispersión y polinización son
     * registros específicos y raros, así que bastan pocos; para la dieta se
     * exigen más para no presentar observaciones aisladas como un rol.
     */
    private const MIN_RECORDS = [
        'seed_disperser' => 1,
        'pollinator' => 1,
        'pollinated_by' => 1,
        'dispersed_by' => 1,
        'default' => 3,
    ];

    /** Grupos amplios o vacíos que no sirven como ejemplo de especie. */
    private const NOT_A_SPECIES = [
        'unknown', 'animalia', 'plantae', 'arthropoda', 'insecta', 'aves', 'mammalia',
        'reptilia', 'amphibia', 'anura', 'squamata', 'rodentia', 'chiroptera',
    ];

    public function __construct(private readonly GlobiService $globi) {}

    /**
     * Descarga las interacciones de GloBI y guarda un resumen por rol en
     * taxon_api_references (api_source = 'globi').
     */
    public function sync(Taxa $taxon): bool
    {
        $result = $this->globi->interactionsFor($taxon->scientific_name);
        if (! $result['success']) {
            return false;
        }

        $roles = [];
        foreach ($result['interactions'] as $interaction) {
            $role = $this->classify($interaction['type'], $this->group($interaction['path']));
            $partner = trim($interaction['partner']);

            if (! $role || $partner === '' || strcasecmp($partner, $taxon->scientific_name) === 0) {
                continue;
            }

            $roles[$role]['records'] = ($roles[$role]['records'] ?? 0) + 1;
            $roles[$role]['partners'][$partner] = ($roles[$role]['partners'][$partner] ?? 0) + 1;
        }

        foreach ($roles as $key => $role) {
            arsort($role['partners']);
            $roles[$key] = [
                'records' => $role['records'],
                'partner_count' => count($role['partners']),
                'partners' => array_slice($role['partners'], 0, self::MAX_PARTNERS_STORED, true),
            ];
        }

        TaxonApiReference::updateOrCreate(
            ['taxon_id' => $taxon->id, 'api_source' => 'globi'],
            [
                'external_id' => $taxon->scientific_name,
                'api_url' => GlobiService::browseUrl($taxon->scientific_name),
                'is_primary' => false,
                'confidence_score' => 1.0,
                'last_verified_at' => now(),
                // GloBI guarda muchas interacciones en ambos sentidos; se cuentan
                // pares distintos (rol, especie) para no inflar la cifra.
                'data' => ['roles' => $roles, 'records_total' => collect($roles)->sum('partner_count')],
            ]
        );

        return true;
    }

    /**
     * Arma la sección "Importancia ecológica" para la ficha. Devuelve null si
     * todavía no hay datos de GloBI para la especie.
     */
    public function present(Taxa $taxon): ?array
    {
        $reference = $taxon->apiReferences->firstWhere('api_source', 'globi');
        $storedRoles = $reference?->data['roles'] ?? [];

        if ($storedRoles === []) {
            return null;
        }

        $localTaxa = $this->localTaxaFor($storedRoles);
        $name = $taxon->common_name ?: $taxon->scientific_name;

        $roles = collect(self::ROLES)
            ->filter(fn ($meta, $key) => isset($storedRoles[$key])
                && $storedRoles[$key]['records'] >= (self::MIN_RECORDS[$key] ?? self::MIN_RECORDS['default']))
            ->map(function ($meta, $key) use ($storedRoles, $localTaxa) {
                $stored = $storedRoles[$key];

                return [
                    'key' => $key,
                    'label' => $meta['label'],
                    'records' => $stored['records'],
                    'partner_count' => $stored['partner_count'],
                    'examples' => $this->examples($stored['partners'], $localTaxa),
                ];
            })
            ->values();

        if ($roles->isEmpty()) {
            return null;
        }

        return [
            'summary' => $this->summary($name, $roles),
            'roles' => $roles->all(),
            'records_total' => (int) ($reference->data['records_total'] ?? 0),
            'source' => [
                'name' => 'GloBI · Global Biotic Interactions',
                'url' => $reference->api_url,
                'synced_at' => $reference->last_verified_at,
            ],
        ];
    }

    public function needsSync(Taxa $taxon): bool
    {
        $reference = $taxon->apiReferences->firstWhere('api_source', 'globi');

        return ! $reference?->last_verified_at || $reference->last_verified_at->lt(now()->subMonths(6));
    }

    /**
     * Texto corto en español con las especies concretas de cada rol.
     */
    private function summary(string $name, Collection $roles): string
    {
        $sentences = $roles->take(3)->map(function (array $role) {
            $examples = collect($role['examples'])->take(3)->map(fn ($example) => $example['common_name']
                ? "{$example['common_name']} ({$example['scientific_name']})"
                : $example['scientific_name']);
            $others = $role['partner_count'] - $examples->count();
            $list = $this->joinList($examples->all());

            if ($others > 0) {
                $list .= ' y '.($others === 1 ? 'otra especie' : "otras {$others} especies");
            }

            return self::ROLES[$role['key']]['verb'].' '.$list;
        });

        $text = ucfirst($name).' '.$this->joinList($sentences->all()).'.';

        $impact = match ($roles->first()['key'] ?? null) {
            'seed_disperser' => ' Al llevar semillas lejos de la planta madre ayuda a regenerar bosques y a conectar ecosistemas.',
            'pollinator' => ' Sin polinizadores muchas plantas no producen frutos ni semillas.',
            'insect_control', 'predator' => ' Al mantener a raya a sus presas contribuye al equilibrio del ecosistema y de los cultivos cercanos.',
            'plant_feeder' => ' Al alimentarse de frutos de tantas plantas, puede contribuir a dispersar sus semillas.',
            'food_source' => ' Es un eslabón de la red alimentaria del que dependen otras especies.',
            default => '',
        };

        return $text.$impact;
    }

    private function examples(array $partners, Collection $localTaxa): array
    {
        $names = collect($partners)
            ->keys()
            ->reject(fn (string $partner) => preg_match('/\d|\bsp\.?$|\bspp\.?$/i', $partner)
                || in_array(mb_strtolower($partner), self::NOT_A_SPECIES, true));

        // Primero especies con nombre binomial; los géneros solo completan la lista.
        [$species, $groups] = $names->partition(fn (string $partner) => preg_match('/^[A-Z][a-z]+ [a-z-]+$/', $partner) === 1);

        return $species->concat($groups)
            ->take(6)
            ->map(function (string $partner) use ($localTaxa) {
                $local = $localTaxa->get(mb_strtolower($partner));

                return [
                    'scientific_name' => $partner,
                    'common_name' => $local?->common_name,
                    'taxon_id' => $local?->id,
                ];
            })
            ->values()
            ->all();
    }

    /** Especies relacionadas que ya existen en el catálogo local (para nombre común y enlace). */
    private function localTaxaFor(array $roles): Collection
    {
        $names = collect($roles)->flatMap(fn ($role) => array_keys($role['partners'] ?? []))->unique()->values();

        return Taxa::query()
            ->whereIn('scientific_name', $names)
            ->get(['id', 'scientific_name', 'common_name'])
            ->keyBy(fn (Taxa $taxon) => mb_strtolower($taxon->scientific_name));
    }

    private function classify(string $type, string $group): ?string
    {
        return match ($type) {
            'dispersalVectorOf' => 'seed_disperser',
            'pollinates', 'visitsFlowersOf' => 'pollinator',
            'eats', 'preysOn' => match ($group) {
                'plant' => 'plant_feeder',
                'arthropod' => 'insect_control',
                'vertebrate' => 'predator',
                default => null,
            },
            'hasDispersalVector' => $group === 'plant' ? null : 'dispersed_by',
            'pollinatedBy', 'flowersVisitedBy' => 'pollinated_by',
            'eatenBy', 'preyedUponBy' => in_array($group, ['vertebrate', 'arthropod'], true) ? 'food_source' : null,
            default => null,
        };
    }

    /** Grupo biológico de la especie relacionada, a partir de su ruta taxonómica en GloBI. */
    private function group(string $path): string
    {
        $parts = array_map('trim', explode('|', $path));
        $has = fn (array $names) => array_intersect($parts, $names) !== [];

        return match (true) {
            $has(['Plantae', 'Viridiplantae', 'Chloroplastida', 'Archaeplastida', 'Tracheophyta', 'Magnoliopsida']) => 'plant',
            $has(['Arthropoda', 'Insecta', 'Arachnida']) => 'arthropod',
            $has(['Mammalia', 'Aves', 'Reptilia', 'Squamata', 'Amphibia', 'Anura', 'Actinopterygii', 'Vertebrata']) => 'vertebrate',
            $has(['Fungi']) => 'fungus',
            default => 'other',
        };
    }

    private function joinList(array $items): string
    {
        $items = array_values($items);

        return match (count($items)) {
            0 => '',
            1 => $items[0],
            default => implode(', ', array_slice($items, 0, -1)).' y '.end($items),
        };
    }
}
