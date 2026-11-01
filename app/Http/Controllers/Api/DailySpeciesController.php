<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SpeciesEcologyText;
use App\Models\Taxa;
use App\Services\Biodiversity\ConservationLabelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DailySpeciesController extends Controller
{
    public function index()
    {
        // Cache key based on date (rotates every 24 hours)
        $date = now()->format('Y-m-d');
        $cacheKey = "daily_species_{$date}";

        $dailySpecies = Cache::remember($cacheKey, 60 * 60 * 24, function () {
            return $this->generateDailySpecies();
        });

        return response()->json($dailySpecies);
    }

    private function generateDailySpecies()
    {
        // 1. Get candidate taxa (fetch more than needed to ensure we have good ones)
        $taxa = Taxa::with(['apiReferences' => function ($query) {
                $query->orderBy('is_primary', 'desc');
            }])
            // We only want species with actual API data which usually means they have photos
            ->whereHas('apiReferences') 
            ->inRandomOrder()
            ->limit(6) // Fetch 6 candidates to select 3
            ->get();

        // 2. Filter valid candidates (must have a medium photo)
        $candidates = $taxa->filter(function ($taxon) {
            return !empty($taxon->enriched_data['default_photo']['medium_url']);
        })->take(3); // Take top 3 valid ones

        // If we don't have enough candidates, we might return less or handle it. 
        // For now, let's proceed with whatever we have.
        
        $apiKey = config('services.google.gemini_key');
        $model = "gemini-3-flash-preview"; // Exactly what the user asked for.

        $results = [];

        // 3. Prepare requests for parallel execution if API key is present
        if ($apiKey && $candidates->isNotEmpty()) {
            
            $responses = Http::pool(function ($pool) use ($candidates, $apiKey, $model) {
                foreach ($candidates as $taxon) {
                    $scientificName = $taxon->scientific_name;
                    $commonName = $taxon->common_name ?? $scientificName;
                    
                    // Specific prompt for this species
                    $prompt = "Genera un único dato curioso, muy interesante y educativo sobre la especie '{$commonName}' ({$scientificName}). El dato debe ser corto (máximo 150 caracteres), en español, y diseñado para captar la atención. No pongas comillas ni intros.";
                    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

                    $pool->as($taxon->id)->timeout(15)->post($url, [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'thinkingConfig' => [
                                'thinkingLevel' => 'low'  
                            ]
                        ]
                    ]);
                }
            });

        }

        // 4. Process results and build final array
        foreach ($candidates as $taxon) {
            $funFact = null;

            // Try to get the API response if available
            if (isset($responses) && isset($responses[$taxon->id])) {
                $response = $responses[$taxon->id];
                if ($response->successful()) {
                    $data = $response->json();
                    $funFact = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                } else {
                    Log::warning("Gemini API failed for {$taxon->scientific_name}: " . $response->body());
                }
            } else if (isset($responses) && $responses[$taxon->id] instanceof \Illuminate\Http\Client\ConnectionException) {
                 Log::warning("Gemini API timed out for {$taxon->scientific_name}");
            }

            // Fallback 1: Wikipedia Summary (truncated)
            if (empty($funFact) && !empty($taxon->enriched_data['wikipedia_summary'])) {
                $funFact = \Illuminate\Support\Str::limit($taxon->enriched_data['wikipedia_summary'], 140);
            }

            // Fallback 2: Generic motivational message
            if (empty($funFact)) {
                $family = $taxon->family ?? 'esta familia';
                $funFact = "Una especie fascinante de {$family}, vital para el equilibrio de nuestro ecosistema colombiano.";
            }

            $results[] = [
                'id' => $taxon->id,
                'name' => $taxon->common_name ?? $taxon->scientific_name,
                'scientificName' => $taxon->scientific_name,
                'image' => $taxon->enriched_data['default_photo']['medium_url'],
                'funFact' => $funFact,
                'author' => $taxon->enriched_data['default_photo']['attribution'] ?? 'Desconocido',
            ];
        }

        // 5. Emergency Fallback (if no candidates found at all)
        if (empty($results)) {
             return [
                [
                    'id' => 1,
                    'name' => 'Jaguar',
                    'scientificName' => 'Panthera onca',
                    'image' => 'https://images.unsplash.com/photo-1575550959106-5a7defe28b56?auto=format&fit=crop&w=800&q=80',
                    'funFact' => 'El jaguar es el felino más grande de América y tiene la mordida más potente de todos los grandes felinos.',
                    'author' => 'Unsplash',
                ],
                [
                    'id' => 2,
                    'name' => 'Cóndor de los Andes',
                    'scientificName' => 'Vultur gryphus',
                    'image' => 'https://images.unsplash.com/photo-1605646124504-18d2054dc7b4?auto=format&fit=crop&w=800&q=80',
                    'funFact' => 'El cóndor andino es una de las aves voladoras más grandes del mundo, con una envergadura de hasta 3.3 metros.',
                    'author' => 'Unsplash',
                ],
                [
                    'id' => 3,
                    'name' => 'Rana Dorada',
                    'scientificName' => 'Phyllobates terribilis',
                    'image' => 'https://images.unsplash.com/photo-1544600277-27b2b3a9856f?auto=format&fit=crop&w=800&q=80',
                    'funFact' => 'Considerada el animal más venenoso del mundo; una sola tiene suficiente veneno para acabar con 10 hombres.',
                    'author' => 'Unsplash',
                ]
             ];
        }

        return $results;
    }

    public function speciesOfTheDay(ConservationLabelService $labels)
    {
        $date = now()->format('Y-m-d');
        $cacheKey = "daily_recommendation_v2_{$date}";

        $species = Cache::remember($cacheKey, 60 * 60 * 24, function () use ($labels) {
            $taxon = $this->pickDailyTaxon();

            if (!$taxon) {
                // Return a placeholder if no species exist in DB
                return [
                    'id' => 0,
                    'name' => 'Especie por descubrir',
                    'scientificName' => 'Incertae sedis',
                    'image' => "https://images.unsplash.com/photo-1518531933037-9a8473035e52?auto=format&fit=crop&w=800&h=500",
                    'description' => "Aún no hay especies registradas en la base de datos. ¡Pronto verás maravillas aquí!",
                    'facts' => [],
                    'date' => now()->format('Y-m-d'),
                    'author' => 'Sistema',
                ];
            }

            $enriched = $taxon->enriched_data;

            return [
                'id' => $taxon->id,
                'name' => $taxon->common_name ?? $taxon->scientific_name,
                'scientificName' => $taxon->scientific_name,
                'image' => $enriched['default_photo']['medium_url']
                         ?? $enriched['default_photo']['url']
                         ?? "https://images.unsplash.com/photo-1518531933037-9a8473035e52?auto=format&fit=crop&w=800&h=500",
                'description' => $this->describe($taxon, $enriched),
                'facts' => $this->facts($taxon, $labels->labelsFor($taxon)),
                'date' => now()->format('Y-m-d'),
                'author' => $enriched['default_photo']['attribution'] ?? 'Desconocido',
            ];
        });

        return response()->json($species);
    }

    /**
     * Especie con foto, prefiriendo las que ya tienen texto propio en la BD
     * (párrafo ecológico) para que la descripción no sea genérica.
     */
    private function pickDailyTaxon(): ?Taxa
    {
        $withPhoto = fn ($t) => !empty($t->enriched_data['default_photo']['medium_url'])
            || !empty($t->enriched_data['default_photo']['url']);

        $pool = fn ($query) => $query->with(['apiReferences', 'ecologyText'])
            ->whereHas('apiReferences')
            ->inRandomOrder()
            ->limit(20)
            ->get()
            ->first($withPhoto);

        return $pool(Taxa::whereHas('ecologyText', fn ($q) => $q->where('status', '!=', SpeciesEcologyText::STATUS_REJECTED)))
            ?? $pool(Taxa::query());
    }

    /**
     * Descripción con datos reales: párrafo ecológico guardado, resumen de
     * Wikipedia o, si no hay ninguno, una frase armada con su taxonomía y estatus.
     */
    private function describe(Taxa $taxon, array $enriched): string
    {
        $ecology = $taxon->ecologyText;
        if ($ecology?->isVisible() && trim((string) $ecology->text) !== '') {
            return trim($ecology->text);
        }

        $wikipedia = trim(strip_tags((string) ($enriched['wikipedia_summary'] ?? '')));
        if ($wikipedia !== '') {
            return Str::limit($wikipedia, 600);
        }

        $name = $taxon->common_name ? "{$taxon->common_name} (<i>{$taxon->scientific_name}</i>)" : "<i>{$taxon->scientific_name}</i>";
        $lineage = collect([
            $taxon->order_name ? "del orden {$taxon->order_name}" : null,
            $taxon->family ? "de la familia {$taxon->family}" : null,
        ])->filter()->implode(', ');
        $sentence = trim("{$name} es una especie {$lineage}").'.';

        $status = collect([
            !empty($enriched['is_endemic']) ? 'es endémica de Colombia' : null,
            empty($enriched['is_endemic']) && !empty($enriched['is_native']) ? 'es nativa de Colombia' : null,
            !empty($enriched['is_introduced']) ? 'fue introducida en Colombia' : null,
        ])->filter()->first();
        if ($status) {
            $sentence .= ' '.Str::ucfirst($status).'.';
        }

        $records = (int) ($taxon->local_records_count ?: $taxon->observation_count);
        if ($records > 0) {
            $sentence .= " Cuenta con {$records} ".($records === 1 ? 'registro' : 'registros').' en EcoIses.';
        }

        return $sentence;
    }

    /**
     * Datos cortos para mostrar junto a la descripción.
     *
     * @return array<int, array{label: string, value: string}>
     */
    private function facts(Taxa $taxon, array $labels): array
    {
        $threat = $labels['national'] ?? $labels['global'] ?? null;

        return collect([
            ['label' => 'Clase', 'value' => $taxon->class],
            ['label' => 'Familia', 'value' => $taxon->family],
            ['label' => 'Origen', 'value' => $labels['origin']['label'] ?? null],
            ['label' => $threat ? "Amenaza ({$threat['scope']})" : 'Amenaza', 'value' => $threat['label'] ?? null],
        ])->filter(fn ($fact) => !empty($fact['value']))->values()->all();
    }
}
