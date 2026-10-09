<?php

namespace App\Services\ExternalApi;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de GloBI (Global Biotic Interactions, https://www.globalbioticinteractions.org).
 *
 * GloBI reúne registros publicados de interacciones entre especies: qué come,
 * qué poliniza, de qué plantas dispersa semillas, quién la depreda… Es gratis,
 * no requiere clave y sus datos son propios de cada especie, a diferencia de
 * los textos generales de EOL.
 */
class GlobiService
{
    private const BASE_URL = 'https://api.globalbioticinteractions.org/interaction';

    private const LIMIT = 500;

    /** Cuando la especie es el objeto del registro, se invierte el verbo. */
    private const INVERSE = [
        'eats' => 'eatenBy',
        'preysOn' => 'preyedUponBy',
        'pollinates' => 'pollinatedBy',
        'visitsFlowersOf' => 'flowersVisitedBy',
        'dispersalVectorOf' => 'hasDispersalVector',
    ];

    /**
     * Interacciones registradas para una especie, en ambas direcciones.
     *
     * @return array{success: bool, interactions?: array<int, array{type: string, partner: string, path: string}>, error?: string}
     */
    public function interactionsFor(string $scientificName): array
    {
        $fields = 'interaction_type,target_taxon_name,target_taxon_path';
        $reverseFields = 'interaction_type,source_taxon_name,source_taxon_path';

        $responses = Http::pool(fn (Pool $pool) => [
            $pool->as('forward')->timeout(30)->get(self::BASE_URL, [
                'sourceTaxon' => $scientificName, 'fields' => $fields, 'limit' => self::LIMIT,
            ]),
            $pool->as('reverse')->timeout(30)->get(self::BASE_URL, [
                // GloBI no acepta varios interactionType a la vez; se filtran abajo con INVERSE.
                'targetTaxon' => $scientificName, 'fields' => $reverseFields, 'limit' => self::LIMIT,
            ]),
        ]);

        $forward = $responses['forward'];
        if (! $forward instanceof \Illuminate\Http\Client\Response || ! $forward->successful()) {
            return ['success' => false, 'error' => 'GloBI no respondió'];
        }

        $interactions = [];
        foreach ($forward->json('data') ?? [] as [$type, $partner, $path]) {
            $interactions[] = ['type' => $type, 'partner' => (string) $partner, 'path' => (string) $path];
        }

        $reverse = $responses['reverse'];
        if ($reverse instanceof \Illuminate\Http\Client\Response && $reverse->successful()) {
            foreach ($reverse->json('data') ?? [] as [$type, $partner, $path]) {
                if (isset(self::INVERSE[$type])) {
                    $interactions[] = ['type' => self::INVERSE[$type], 'partner' => (string) $partner, 'path' => (string) $path];
                }
            }
        }

        return ['success' => true, 'interactions' => $interactions];
    }

    public static function browseUrl(string $scientificName): string
    {
        return 'https://www.globalbioticinteractions.org/?sourceTaxon='.rawurlencode($scientificName);
    }
}
