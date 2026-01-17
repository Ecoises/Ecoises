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
        
        // If we didn't find 3 (e.g. no internet or no photos), fill with placeholders if necessary
        // But logic above should find them if database is populated.

        return $selectedSpecies;
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
