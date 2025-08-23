<?php

namespace App\Services;

use App\Models\Taxa;
use App\Models\TaxonApiReference;
use App\Models\UnifiedApiCache;
use App\Models\ApiConfiguration;
use App\Models\Location;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; // Importar Log
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth; // Importar Auth

class ExternalApisService
{
    /**
     * Buscar especies cercanas a una ubicación
     */
    public function findNearbySpecies(float $latitude, float $longitude, float $radiusKm): array
    {
        $cacheKey = 'nearby_species_' . md5("{$latitude}_{$longitude}_{$radiusKm}");
        $location = Location::whereRaw("(6371 * acos(cos(radians(?)) 
            * cos(radians(latitude)) 
            * cos(radians(longitude) - radians(?)) 
            + sin(radians(?)) 
            * sin(radians(latitude)))) < radius_km", [$latitude, $longitude, $latitude])
            ->first();

        $locationId = $location ? $location->id : null;

        // Verificar caché
        $cached = UnifiedApiCache::where('cache_key', $cacheKey)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            $cached->increment('hit_count');
            $cached->update(['last_accessed_at' => now()]);
            return json_decode($cached->response_data, true);
        }

        try {
            $results = [];
            $apiConfigs = ApiConfiguration::where('is_active', true)->whereIn('api_source', ['inaturalist', 'gbif'])->get();

            foreach ($apiConfigs as $config) {
                switch ($config->api_source) {
                    case 'inaturalist':
                        $response = $this->queryINaturalist($latitude, $longitude, $radiusKm, $config);
                        break;
                    case 'gbif':
                        $response = $this->queryGbif($latitude, $longitude, $radiusKm, $config);
                        break;
                    default:
                        continue 2;
                }

                $results = array_merge($results, $this->normalizeSpeciesData($response, $config->api_source));
            }

            // Añadir estado de conservación desde IUCN
            $results = $this->enrichWithIUCNData($results);

            // Guardar en caché
            $ttl = $this->getCacheTtl('search', $apiConfigs->first());
            UnifiedApiCache::create([
                'cache_key' => $cacheKey,
                'cache_type' => 'search_results',
                'taxon_id' => null,
                'user_id' => Auth::check() ? Auth::id() : null, // Manejar caso sin usuario autenticado
                'api_source' => $apiConfigs->first()->api_source,
                'data_type' => 'search',
                'request_url' => null,
                'request_params' => json_encode(['latitude' => $latitude, 'longitude' => $longitude, 'radius_km' => $radiusKm]),
                'response_data' => json_encode($results),
                'response_metadata' => json_encode(['generated_at' => now()]),
                'expires_at' => now()->addSeconds($ttl),
                'last_accessed_at' => now(),
                'hit_count' => 1
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('Error en findNearbySpecies: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtener detalles de una especie
     */
    public function getTaxonDetails(int $taxonId): array
    {
        $cacheKey = 'taxon_details_' . $taxonId;
        $taxon = Taxa::findOrFail($taxonId);

        // Verificar caché
        $cached = UnifiedApiCache::where('cache_key', $cacheKey)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            $cached->increment('hit_count');
            $cached->update(['last_accessed_at' => now()]);
            return json_decode($cached->response_data, true);
        }

        try {
            $references = TaxonApiReference::where('taxon_id', $taxonId)->get();
            $results = [];

            foreach ($references as $reference) {
                $config = ApiConfiguration::where('api_source', $reference->api_source)->first();
                if (!$config || !$config->is_active) {
                    continue;
                }

                switch ($reference->api_source) {
                    case 'inaturalist':
                        $response = $this->queryINaturalistTaxon($reference->external_id, $config);
                        break;
                    case 'gbif':
                        $response = $this->queryGbifTaxon($reference->external_id, $config);
                        break;
                    case 'iucn':
                        $response = $this->queryIUCNTaxon($reference->external_id, $config);
                        break;
                    default:
                        continue 2;
                }

                $results[$reference->api_source] = $this->normalizeTaxonData($response, $reference->api_source);
            }

            // Guardar en caché
            $ttl = $this->getCacheTtl('description', $config);
            UnifiedApiCache::create([
                'cache_key' => $cacheKey,
                'cache_type' => 'taxon_data',
                'taxon_id' => $taxonId,
                'user_id' => Auth::check() ? Auth::id() : null, // Manejar caso sin usuario autenticado
                'api_source' => $config->api_source,
                'data_type' => 'description',
                'request_url' => $reference->api_url,
                'request_params' => json_encode(['external_id' => $reference->external_id]),
                'response_data' => json_encode($results),
                'response_metadata' => json_encode(['generated_at' => now()]),
                'expires_at' => now()->addSeconds($ttl),
                'last_accessed_at' => now(),
                'hit_count' => 1
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('Error en getTaxonDetails: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sugerir especies basadas en ubicación y/o foto
     */
    public function suggestSpecies(float $latitude, float $longitude, ?UploadedFile $photo = null): array
    {
        $cacheKey = 'suggest_species_' . md5("{$latitude}_{$longitude}_" . ($photo ? $photo->getClientOriginalName() : 'no_photo'));
        $location = Location::whereRaw("(6371 * acos(cos(radians(?)) 
            * cos(radians(latitude)) 
            * cos(radians(longitude) - radians(?)) 
            + sin(radians(?)) 
            * sin(radians(latitude)))) < radius_km", [$latitude, $longitude, $latitude])
            ->first();

        $locationId = $location ? $location->id : null;

        // Verificar caché
        $cached = UnifiedApiCache::where('cache_key', $cacheKey)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            $cached->increment('hit_count');
            $cached->update(['last_accessed_at' => now()]);
            return json_decode($cached->response_data, true);
        }

        try {
            $results = [];
            $apiConfigs = ApiConfiguration::where('is_active', true)->whereIn('api_source', ['inaturalist'])->get();

            foreach ($apiConfigs as $config) {
                if ($photo && $config->api_source === 'inaturalist') {
                    $response = $this->queryINaturalistVision($photo, $latitude, $longitude, $config);
                    $results = array_merge($results, $this->normalizeVisionData($response, $config->api_source));
                } else {
                    $response = $this->queryINaturalist($latitude, $longitude, 10, $config);
                    $results = array_merge($results, $this->normalizeSpeciesData($response, $config->api_source));
                }
            }

            // Añadir estado de conservación desde IUCN
            $results = $this->enrichWithIUCNData($results);

            // Guardar en caché
            $ttl = $this->getCacheTtl('search', $apiConfigs->first());
            UnifiedApiCache::create([
                'cache_key' => $cacheKey,
                'cache_type' => 'search_results',
                'taxon_id' => null,
                'user_id' => Auth::check() ? Auth::id() : null, // Manejar caso sin usuario autenticado
                'api_source' => $apiConfigs->first()->api_source,
                'data_type' => 'search',
                'request_url' => null,
                'request_params' => json_encode(['latitude' => $latitude, 'longitude' => $longitude, 'has_photo' => !is_null($photo)]),
                'response_data' => json_encode($results),
                'response_metadata' => json_encode(['generated_at' => now()]),
                'expires_at' => now()->addSeconds($ttl),
                'last_accessed_at' => now(),
                'hit_count' => 1
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('Error en suggestSpecies: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Consultar iNaturalist para especies cercanas
     */
    protected function queryINaturalist(float $latitude, float $longitude, float $radiusKm, ApiConfiguration $config): array
    {
        $url = $config->base_url . '/observations';
        $params = [
            'lat' => $latitude,
            'lng' => $longitude,
            'radius' => $radiusKm,
            'per_page' => 50
        ];

        if ($config->api_key_required) {
            $params['access_token'] = $config->api_key;
        }

        $response = Http::get($url, $params);

        if ($response->successful()) {
            return $response->json()['results'] ?? [];
        }

        Log::warning('Error en iNaturalist API: ' . $response->status());
        return [];
    }

    /**
     * Consultar iNaturalist para detalles de un taxón
     */
    protected function queryINaturalistTaxon(string $externalId, ApiConfiguration $config): array
    {
        $url = $config->base_url . '/taxa/' . $externalId;
        $params = $config->api_key_required ? ['access_token' => $config->api_key] : [];

        $response = Http::get($url, $params);

        if ($response->successful()) {
            return $response->json()['results'][0] ?? [];
        }

        Log::warning('Error en iNaturalist taxon API: ' . $response->status());
        return [];
    }

    /**
     * Consultar iNaturalist para identificación por visión
     */
    protected function queryINaturalistVision(UploadedFile $photo, float $latitude, float $longitude, ApiConfiguration $config): array
    {
        $url = $config->base_url . '/computer_vision/score_image';
        $response = Http::attach('image', file_get_contents($photo->getRealPath()), $photo->getClientOriginalName())
            ->post($url, [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'access_token' => $config->api_key_required ? $config->api_key : null
            ]);

        if ($response->successful()) {
            return $response->json()['results'] ?? [];
        }

        Log::warning('Error en iNaturalist vision API: ' . $response->status());
        return [];
    }

    /**
     * Consultar GBIF para especies cercanas
     */
    protected function queryGbif(float $latitude, float $longitude, float $radiusKm, ApiConfiguration $config): array
    {
        $url = $config->base_url . '/occurrence/search';
        $params = [
            'decimalLatitude' => $latitude,
            'decimalLongitude' => $longitude,
            'radius' => $radiusKm * 1000, // GBIF usa metros
            'limit' => 50
        ];

        $response = Http::get($url, $params);

        if ($response->successful()) {
            return $response->json()['results'] ?? [];
        }

        Log::warning('Error en GBIF API: ' . $response->status());
        return [];
    }

    /**
     * Consultar GBIF para detalles de un taxón
     */
    protected function queryGbifTaxon(string $externalId, ApiConfiguration $config): array
    {
        $url = $config->base_url . '/species/' . $externalId;
        $response = Http::get($url);

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        Log::warning('Error en GBIF taxon API: ' . $response->status());
        return [];
    }

    /**
     * Consultar IUCN Red List para detalles de un taxón
     */
    protected function queryIUCNTaxon(string $externalId, ApiConfiguration $config): array
    {
        $url = $config->base_url . '/species/id/' . $externalId;
        $params = [
            'token' => $config->api_key
        ];

        $response = Http::get($url, $params);

        if ($response->successful()) {
            return $response->json()['result'][0] ?? [];
        }

        Log::warning('Error en IUCN Red List API: ' . $response->status());
        return [];
    }

    /**
     * Enriquecer datos de especies con estado de conservación de IUCN
     */
    protected function enrichWithIUCNData(array $species): array
    {
        $config = ApiConfiguration::where('api_source', 'iucn')->where('is_active', true)->first();
        if (!$config) {
            return $species;
        }

        foreach ($species as &$item) {
            $reference = TaxonApiReference::where('taxon_id', $item['taxon_id'])
                ->where('api_source', 'iucn')
                ->first();

            if ($reference) {
                $cacheKey = 'iucn_conservation_' . $reference->external_id;
                $cached = UnifiedApiCache::where('cache_key', $cacheKey)
                    ->where('expires_at', '>', now())
                    ->first();

                if ($cached) {
                    $cached->increment('hit_count');
                    $cached->update(['last_accessed_at' => now()]);
                    $data = json_decode($cached->response_data, true);
                    $item['conservation_status'] = $data['conservation_status'] ?? null;
                } else {
                    $response = $this->queryIUCNTaxon($reference->external_id, $config);
                    $normalized = $this->normalizeTaxonData($response, 'iucn');
                    $item['conservation_status'] = $normalized['conservation_status'] ?? null;

                    // Guardar en caché
                    $ttl = $this->getCacheTtl('conservation', $config);
                    UnifiedApiCache::create([
                        'cache_key' => $cacheKey,
                        'cache_type' => 'conservation_data',
                        'taxon_id' => $item['taxon_id'],
                        'user_id' => Auth::check() ? Auth::id() : null, // Manejar caso sin usuario autenticado
                        'api_source' => 'iucn',
                        'data_type' => 'conservation',
                        'request_url' => $config->base_url . '/species/id/' . $reference->external_id,
                        'request_params' => json_encode(['external_id' => $reference->external_id]),
                        'response_data' => json_encode($normalized),
                        'response_metadata' => json_encode(['generated_at' => now()]),
                        'expires_at' => now()->addSeconds($ttl),
                        'last_accessed_at' => now(),
                        'hit_count' => 1
                    ]);
                }
            }
        }

        return $species;
    }

    /**
     * Normalizar datos de especies de APIs externas
     */
    protected function normalizeSpeciesData(array $data, string $apiSource): array
    {
        $normalized = [];
        foreach ($data as $item) {
            $taxonId = TaxonApiReference::where('api_source', $apiSource)
                ->where('external_id', $item['taxon_id'] ?? $item['key'] ?? null)
                ->value('taxon_id');

            if ($taxonId) {
                $normalized[] = [
                    'taxon_id' => $taxonId,
                    'scientific_name' => $item['scientific_name'] ?? $item['scientificName'] ?? null,
                    'common_name' => $item['common_name'] ?? $item['vernacularName'] ?? null,
                    'confidence' => $item['score'] ?? 0.5
                ];
            }
        }
        return $normalized;
    }

    /**
     * Normalizar datos de visión por computadora
     */
    protected function normalizeVisionData(array $data, string $apiSource): array
    {
        $normalized = [];
        foreach ($data as $item) {
            $taxonId = TaxonApiReference::where('api_source', $apiSource)
                ->where('external_id', $item['taxon_id'] ?? null)
                ->value('taxon_id');

            if ($taxonId) {
                $normalized[] = [
                    'taxon_id' => $taxonId,
                    'scientific_name' => $item['taxon']['scientific_name'] ?? null,
                    'common_name' => $item['taxon']['common_name'] ?? null,
                    'confidence' => $item['score'] ?? 0.5
                ];
            }
        }
        return $normalized;
    }

    /**
     * Normalizar datos de detalles de taxón
     */
    protected function normalizeTaxonData(array $data, string $apiSource): array
    {
        switch ($apiSource) {
            case 'iucn':
                return [
                    'conservation_status' => $data['category'] ?? null,
                    'description' => $data['rationale'] ?? null,
                    'distribution' => $data['geographicrange'] ?? [],
                    'threats' => $data['threats'] ?? [],
                    'conservation_measures' => $data['conservationmeasures'] ?? []
                ];
            default:
                return [
                    'description' => $data['description'] ?? $data['wiki_summary'] ?? null,
                    'images' => $data['images'] ?? $data['media'] ?? [],
                    'distribution' => $data['distribution'] ?? [],
                    'conservation_status' => $data['conservation_status'] ?? $data['iucn_status'] ?? null,
                    'characteristics' => $data['characteristics'] ?? []
                ];
        }
    }

    /**
     * Obtener TTL de caché según el tipo de datos
     */
    protected function getCacheTtl(string $dataType, ?ApiConfiguration $config): int
    {
        $ttlFields = [
            'description' => 'cache_ttl_description',
            'images' => 'cache_ttl_images',
            'sounds' => 'cache_ttl_sounds',
            'distribution' => 'cache_ttl_distribution',
            'conservation' => 'cache_ttl_conservation',
            'characteristics' => 'cache_ttl_characteristics',
            'references' => 'cache_ttl_references',
            'search' => 'cache_ttl_description' // Usar description como fallback para search
        ];

        return $config ? ($config->{$ttlFields[$dataType] ?? 'cache_ttl_description'} ?? 604800) : 604800; // 1 semana por defecto
    }
}