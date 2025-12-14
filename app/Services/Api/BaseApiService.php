<?php

namespace App\Services\Api;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use App\Models\UnifiedApiCache;
use Carbon\Carbon;

abstract class BaseApiService implements ApiServiceInterface
{
    /**
     * Nombre de la API (ej: 'inaturalist', 'gbif')
     *
     * @var string
     */
    protected $apiName;
    
    /**
     * Configuración base de la API
     *
     * @var array
     */
    protected $config;
    
    /**
     * Tiempo de vida de la caché en minutos
     *
     * @var int
     */
    protected $cacheTtl = 1440; // 24 horas por defecto
    
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->config = config("services.{$this->apiName}", []);
        $this->initialize();
    }
    
    /**
     * Inicialización del servicio
     */
    protected function initialize()
    {
        // Método que puede ser sobrescrito por las clases hijas
    }
    
    /**
     * Realiza una petición HTTP a la API
     *
     * @param string $method
     * @param string $endpoint
     * @param array $params
     * @param bool $useCache
     * @return array
     */
    protected function makeRequest(string $method, string $endpoint, array $params = [], bool $useCache = true): array
    {
        $cacheKey = $this->generateCacheKey($method, $endpoint, $params);
        
        // Intentar obtener de caché si está habilitado
        if ($useCache) {
            $cachedResponse = $this->getFromCache($cacheKey);
            if ($cachedResponse !== null) {
                return $cachedResponse;
            }
        }
        
        try {
            $url = rtrim($this->config['base_url'], '/') . '/' . ltrim($endpoint, '/');
            
            $response = Http::withHeaders($this->getDefaultHeaders())
                ->timeout($this->config['timeout'] ?? 30)
                ->$method($url, $method === 'get' ? $params : []);
                
            $response->throw();
            
            $data = $response->json();
            
            // Guardar en caché si la respuesta fue exitosa
            if ($useCache) {
                $this->saveToCache($cacheKey, $endpoint, $data);
            }
            
            return [
                'success' => true,
                'data' => $data,
                'cached' => false,
                'api' => $this->apiName,
                'endpoint' => $endpoint,
            ];
            
        } catch (RequestException $e) {
            Log::error("Error en la petición a {$this->apiName} - {$endpoint}", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'params' => $params,
            ]);
            
            return [
                'success' => false,
                'error' => $this->mapError($e->getCode(), $e->getMessage()),
                'api' => $this->apiName,
                'endpoint' => $endpoint,
            ];
        }
    }
    
    /**
     * Obtiene los encabezados por defecto para las peticiones
     *
     * @return array
     */
    protected function getDefaultHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'BiodiversidadAPI/1.0',
        ];
        
        // Agregar token de autenticación si existe
        if (!empty($this->config['api_key'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['api_key'];
        }
        
        return $headers;
    }
    
    /**
     * Genera una clave única para el caché basada en la petición
     *
     * @param string $method
     * @param string $endpoint
     * @param array $params
     * @return string
     */
    protected function generateCacheKey(string $method, string $endpoint, array $params = []): string
    {
        $key = "{$this->apiName}:{$method}:{$endpoint}";
        
        if (!empty($params)) {
            ksort($params); // Ordenar parámetros para consistencia
            $key .= ':' . md5(json_encode($params));
        }
        
        return $key;
    }
    
    /**
     * Obtiene una respuesta del caché si está disponible
     *
     * @param string $cacheKey
     * @return array|null
     */
    protected function getFromCache(string $cacheKey): ?array
    {
        $cached = UnifiedApiCache::where('cache_key', $cacheKey)
            ->where('expires_at', '>', now())
            ->first();
            
        if ($cached) {
            // Actualizar contador de accesos y última fecha de acceso
            $cached->increment('hit_count');
            $cached->update(['last_accessed_at' => now()]);
            
            return [
                'success' => true,
                'data' => $cached->response_data,
                'cached' => true,
                'api' => $this->apiName,
                'endpoint' => $cached->endpoint,
            ];
        }
        
        return null;
    }
    
    /**
     * Guarda una respuesta en el caché
     *
     * @param string $cacheKey
     * @param string $endpoint
     * @param mixed $data
     * @param int|null $ttl
     * @return void
     */
    protected function saveToCache(string $cacheKey, string $endpoint, $data, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->cacheTtl;
        
        UnifiedApiCache::updateOrCreate(
            ['cache_key' => $cacheKey],
            [
                'api_source' => $this->apiName,
                'endpoint' => $endpoint,
                'response_data' => $data,
                'expires_at' => now()->addMinutes($ttl),
                'last_accessed_at' => now(),
                'hit_count' => 0,
            ]
        );
    }
    
    /**
     * Mapea los códigos de error HTTP a mensajes de error más descriptivos
     *
     * @param int $statusCode
     * @param string $defaultMessage
     * @return array
     */
    protected function mapError(int $statusCode, string $defaultMessage = ''): array
    {
        $messages = [
            400 => 'Solicitud incorrecta',
            401 => 'No autorizado - Verifica tus credenciales',
            403 => 'Prohibido - No tienes permisos para acceder a este recurso',
            404 => 'Recurso no encontrado',
            429 => 'Demasiadas solicitudes - Has excedido el límite de peticiones',
            500 => 'Error interno del servidor',
            502 => 'Error de puerta de enlace',
            503 => 'Servicio no disponible',
            504 => 'Tiempo de espera agotado',
        ];
        
        return [
            'code' => $statusCode,
            'message' => $messages[$statusCode] ?? $defaultMessage ?: 'Error desconocido',
            'timestamp' => now()->toIso8601String(),
        ];
    }
    
    /**
     * Limpia los parámetros eliminando valores nulos o vacíos
     *
     * @param array $params
     * @return array
     */
    protected function cleanParams(array $params): array
    {
        return array_filter($params, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });
    }
}
