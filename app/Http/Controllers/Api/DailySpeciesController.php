<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Taxa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        // Get random taxa that have API references (likely to have photos)
        // We fetch a bit more than 3 to ensure we find ones with photos
        $taxa = Taxa::with(['apiReferences' => function ($query) {
                // Ensure we prefer primary api references
                $query->orderBy('is_primary', 'desc');
            }])
            ->whereHas('apiReferences')
            ->inRandomOrder()
            ->limit(10)
            ->get();

        $selectedSpecies = [];
        $apiKey = env('GOOGLE_AI_STUDIO_KEY');
        
        if (!$apiKey) {
             Log::error('GOOGLE_AI_STUDIO_KEY is missing in .env');
             // Fallback or error? We'll return what we have or generic data
        }

        foreach ($taxa as $taxon) {
            if (count($selectedSpecies) >= 3) {
                break;
            }

            $enrichedData = $taxon->enriched_data;
            if (empty($enrichedData['default_photo']['medium_url'])) {
                continue;
            }

            $scientificName = $taxon->scientific_name;
            $commonName = $taxon->common_name ?? $scientificName;

            // Generate Fun Fact with Gemini
            $funFact = $this->generateFunFact($scientificName, $commonName, $apiKey);

            $selectedSpecies[] = [
                'id' => $taxon->id,
                'name' => $commonName, // Use common name as main title
                'scientificName' => $scientificName,
                'image' => $enrichedData['default_photo']['medium_url'],
                'funFact' => $funFact,
                'author' => $enrichedData['default_photo']['attribution'] ?? 'Desconocido',
            ];
        }
        
        // If no species found (empty DB), return placeholders
        if (empty($selectedSpecies)) {
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
                    'funFact' => 'Esta pequeña rana es considerada el animal más venenoso del mundo; una sola tiene suficiente veneno para acabar con 10 hombres.',
                    'author' => 'Unsplash',
                ]
             ];
        }

        return $selectedSpecies;
    }

    public function speciesOfTheDay()
    {
        $date = now()->format('Y-m-d');
        $cacheKey = "daily_recommendation_{$date}";

        $species = Cache::remember($cacheKey, 60 * 60 * 24, function () {
            // Get random taxon with good photo
            $taxon = Taxa::whereHas('apiReferences') // Ensure it has some external data
                ->inRandomOrder()
                ->limit(20) // Get a pool to check for good photos
                ->get()
                ->filter(function ($t) {
                    return !empty($t->enriched_data['default_photo']['medium_url']);
                })
                ->first();

            if (!$taxon) {
                // Return a placeholder if no species exist in DB
                return [
                    'id' => 0,
                    'name' => 'Especie por descubrir',
                    'scientificName' => 'Incertae sedis',
                    'image' => "https://images.unsplash.com/photo-1518531933037-9a8473035e52?auto=format&fit=crop&w=800&h=500",
                    'description' => "Aún no hay especies registradas en la base de datos. ¡Pronto verás maravillas aquí!",
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
                'description' => $enriched['wikipedia_summary'] 
                               ?? "Descubre la biodiversidad de Colombia con el {$taxon->common_name}.",
                'date' => now()->format('Y-m-d'),
                'author' => $enriched['default_photo']['attribution'] ?? 'Desconocido',
            ];
        });

        return response()->json($species);
    }

    private function generateFunFact($scientificName, $commonName, $apiKey)
    {
        if (!$apiKey) {
            return "Dato curioso no disponible (Falta configuración de API Key).";
        }

        try {
            // Using Gemini 3 Flash Preview as per user availability
            $model = "gemini-3-flash-preview"; 
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

            $prompt = "Genera un único dato curioso, muy interesante y educativo sobre la especie '{$commonName}' ({$scientificName}). El dato debe ser corto (máximo 150 caracteres), en español, y diseñado para captar la atención. No pongas comillas ni intros.";

            $response = Http::post($url, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? "Dato curioso no disponible.";
            } else {
                Log::error("Gemini API Error: " . $response->body());
                return "Dato curioso no disponible temporalmente.";
            }

        } catch (\Exception $e) {
            Log::error("Gemini API Exception: " . $e->getMessage());
            return "Dato curioso no disponible.";
        }
    }
}
