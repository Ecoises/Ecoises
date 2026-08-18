<?php

namespace App\Services\Api;

use App\Services\SpeciesMerger;

class EolService extends BaseApiService
{
    protected $apiName = 'eol';

    protected function initialize(): void
    {
        $this->config['base_url'] = $this->config['base_url'] ?? 'https://eol.org/api';
        $this->cacheTtl = (int) ($this->config['cache_ttl'] ?? 43200); // 30 days
    }

    public function searchTaxon(string $scientificName): array
    {
        $canonicalName = SpeciesMerger::stripAuthorship($scientificName);
        $response = $this->makeRequest('get', '/search/1.0.json', [
            'q' => $canonicalName,
            'page' => 1,
            'exact' => 1,
        ]);

        if (!($response['success'] ?? false)) {
            return $response;
        }

        $result = collect($response['data']['results'] ?? [])->first(function (array $result) use ($canonicalName) {
            return mb_strtolower(SpeciesMerger::stripAuthorship($result['title'] ?? ''))
                === mb_strtolower($canonicalName);
        });

        if (!$result) {
            return [
                'success' => false,
                'error' => ['code' => 404, 'message' => 'Taxón no encontrado en EOL'],
                'api' => $this->apiName,
            ];
        }

        return [
            'success' => true,
            'data' => $result,
            'cached' => $response['cached'] ?? false,
            'api' => $this->apiName,
        ];
    }

    public function getTaxonById(string $id): array
    {
        return $this->makeRequest('get', "/pages/1.0/{$id}.json", [
            'details' => 'true',
            'texts_per_page' => 75,
            'subjects' => 'all',
            'language' => 'es',
            'licenses' => 'all',
            'common_names' => 'true',
            'references' => 'true',
        ]);
    }

    public function getEcologyProfile(string $scientificName): array
    {
        $search = $this->searchTaxon($scientificName);

        if (!($search['success'] ?? false)) {
            return $search;
        }

        // EOL solicita mantener como máximo una petición por segundo.
        if (!($search['cached'] ?? false)) {
            usleep(1_050_000);
        }

        $page = $this->getTaxonById((string) $search['data']['id']);

        if (!($page['success'] ?? false)) {
            return $page;
        }

        return [
            'success' => true,
            'data' => $this->normalizeEcologyProfile(
                $page['data']['taxonConcept'] ?? [],
                (int) $search['data']['id']
            ),
            'cached' => ($search['cached'] ?? false) && ($page['cached'] ?? false),
            'api' => $this->apiName,
        ];
    }

    protected function normalizeEcologyProfile(array $taxonConcept, int $eolId): array
    {
        $articles = collect($taxonConcept['dataObjects'] ?? [])
            ->filter(fn (array $object) => str_ends_with($object['dataType'] ?? '', '/Text'))
            ->map(fn (array $object) => $this->normalizeArticle($object))
            ->filter(fn (array $article) => $article['text'] !== '')
            ->values();

        $habitat = $this->selectArticle($articles, ['habitat']);
        $diet = $this->selectArticle($articles, [
            'trophic strategy', 'food', 'feeding', 'diet', 'associations',
        ]);
        $ecology = $this->selectArticle($articles, [
            'ecology', 'general ecology', 'associations', 'behavior', 'biology',
        ]);
        $summary = $this->selectArticle($articles, ['brief summary']);
        $role = $this->inferEcologicalRole($articles);

        return [
            'schema_version' => 2,
            'eol_id' => $eolId,
            'eol_url' => "https://eol.org/pages/{$eolId}",
            'scientific_name' => $taxonConcept['scientificName'] ?? null,
            'role' => $role,
            // El destacado responde una pregunta distinta al resumen: que funcion
            // cumple la especie. Si no hay evidencia explicita, no mostramos una
            // descripcion general como si fuera importancia ecologica.
            'highlight' => $role,
            'habitat' => $habitat,
            'diet' => $diet,
            'natural_history' => $summary,
            'enriched_at' => now()->toIso8601String(),
        ];
    }

    protected function normalizeArticle(array $object): array
    {
        $text = html_entity_decode(strip_tags($object['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return [
            'text' => $this->shorten($text),
            'full_text' => $text,
            'subject' => array_values($object['subject'] ?? []),
            'language' => $object['language'] ?? null,
            'title' => $object['title'] ?? null,
            'source_url' => !empty($object['source']) ? $object['source'] : null,
            'license' => $object['license'] ?? null,
            'rights_holder' => $object['rightsHolder'] ?? null,
            'provider' => collect($object['agents'] ?? [])->pluck('full_name')->filter()->first(),
            'vetted_status' => $object['vettedStatus'] ?? null,
            'rating' => isset($object['dataRating']) ? (float) $object['dataRating'] : null,
        ];
    }

    protected function selectArticle($articles, array $subjects): ?array
    {
        return $articles
            ->filter(function (array $article) use ($subjects) {
                $articleSubjects = mb_strtolower(implode(' ', $article['subject']));

                return collect($subjects)->contains(
                    fn (string $subject) => str_contains($articleSubjects, mb_strtolower($subject))
                );
            })
            ->sortByDesc(function (array $article) {
                $languageScore = match ($article['language']) {
                    'es' => 300,
                    'en' => 200,
                    null => 100,
                    default => 0,
                };
                $trustScore = $article['vetted_status'] === 'Trusted' ? 20 : 0;
                $sourceScore = str_contains(mb_strtolower($article['source_url'] ?? ''), 'wikipedia') ? 0 : 10;

                return $languageScore + $trustScore + $sourceScore + ($article['rating'] ?? 0);
            })
            ->map(fn (array $article) => collect($article)->except('full_text')->all())
            ->first();
    }

    protected function inferEcologicalRole($articles): ?array
    {
        $evidenceArticles = $articles
            ->filter(fn (array $article) => in_array($article['language'], ['es', 'en', null], true))
            ->sortByDesc(function (array $article) {
                $subjects = mb_strtolower(implode(' ', $article['subject']));
                $provider = mb_strtolower(($article['provider'] ?? '') . ' ' . ($article['source_url'] ?? ''));
                $ecologyScore = preg_match('/ecology|biology|feeding|diet|trophic|association/u', $subjects) ? 30 : 0;
                $sourceScore = str_contains($provider, 'wikipedia') ? 0 : 20;
                $trustScore = $article['vetted_status'] === 'Trusted' ? 10 : 0;

                return $ecologyScore + $sourceScore + $trustScore + ($article['rating'] ?? 0);
            });

        $roles = [
            [
                'patterns' => ['ecosystem engineer', 'ecosystem engineering', 'ingeniero del ecosistema'],
                'name' => 'Ingeniero del ecosistema',
                'description' => 'Modifica físicamente su entorno y crea o transforma recursos que pueden ser aprovechados por otras especies.',
            ],
            [
                'patterns' => ['carroñ', 'carrion', 'scaveng'],
                'name' => 'Carroñero',
                'description' => 'Consume restos de animales y contribuye al reciclaje de materia orgánica dentro del ecosistema.',
            ],
            [
                'patterns' => ['poliniz', 'pollinat'],
                'name' => 'Polinizador',
                'description' => 'Transporta polen entre flores y favorece la reproducción de las plantas con las que interactúa.',
            ],
            [
                'patterns' => ['dispersión de semillas', 'dispersor de semillas', 'seed dispers'],
                'name' => 'Dispersor de semillas',
                'description' => 'Transporta semillas y puede favorecer la regeneración de las plantas con las que interactúa.',
            ],
            [
                'patterns' => ['descompon', 'decompos', 'saprotroph', 'saprob'],
                'name' => 'Descomponedor',
                'description' => 'Participa en la descomposición de materia orgánica y en el retorno de nutrientes al ambiente.',
            ],
            [
                'patterns' => ['preys on', 'feeds on insects', 'insectivor', 'depredador', 'predator', 'predation'],
                'name' => 'Regulador de poblaciones',
                'description' => 'Al consumir otros organismos, puede contribuir a regular sus poblaciones y a mantener el equilibrio de la red alimentaria.',
            ],
            [
                'patterns' => ['fixes nitrogen', 'nitrogen fixation', 'fijación de nitrógeno', 'fija nitrógeno'],
                'name' => 'Fijador de nitrógeno',
                'description' => 'Contribuye a incorporar nitrógeno biológicamente disponible, un nutriente esencial para la productividad del ecosistema.',
            ],
            [
                'patterns' => ['bioindicator', 'bioindicador', 'indicator species', 'especie indicadora'],
                'name' => 'Indicador ambiental',
                'description' => 'Su presencia, ausencia o abundancia puede aportar información sobre el estado y los cambios del ambiente.',
            ],
        ];

        foreach ($evidenceArticles as $article) {
            $text = mb_strtolower($article['full_text']);

            foreach ($roles as $role) {
                if (collect($role['patterns'])->contains(fn (string $pattern) => str_contains($text, $pattern))) {
                    return [
                        'name' => $role['name'],
                        'text' => $role['description'],
                        'evidence_level' => 'derived_from_source',
                        'evidence_subject' => $article['subject'],
                        'language' => 'es',
                        'source_url' => $article['source_url'],
                        'license' => $article['license'],
                        'provider' => $article['provider'] ?: 'Encyclopedia of Life',
                    ];
                }
            }
        }

        return null;
    }

    protected function shorten(string $text, int $limit = 520): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $excerpt = mb_substr($text, 0, $limit);
        $lastSentence = max(mb_strrpos($excerpt, '.') ?: 0, mb_strrpos($excerpt, ';') ?: 0);

        return rtrim(mb_substr($excerpt, 0, $lastSentence > 180 ? $lastSentence + 1 : $limit)) . '…';
    }

    public function getTaxonObservations(string $taxonId, array $params = []): array
    {
        return ['success' => false, 'error' => ['code' => 501, 'message' => 'EOL no provee ocurrencias']];
    }

    public function getLocationInfo(string $locationId): array
    {
        return ['success' => false, 'error' => ['code' => 501, 'message' => 'EOL no provee ubicaciones']];
    }

    public function getApiInfo(): array
    {
        return [
            'name' => 'Encyclopedia of Life',
            'documentation' => 'https://eol.org/docs/what-is-eol/data-services',
            'base_url' => $this->config['base_url'],
        ];
    }
}
