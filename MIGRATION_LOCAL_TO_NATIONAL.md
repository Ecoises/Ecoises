# Escalamiento Local → Nacional: Cambios Necesarios

## 1. Cambios en Configuración (.env)

### MVP Local (Actual)

```ini
# .env (actual)
QUEUE_CONNECTION=database     # ← Usar BD como queue
CACHE_DRIVER=file
SESSION_DRIVER=file

DB_CONNECTION=mysql
DB_HOST=localhost
DB_DATABASE=ecoises
DB_USERNAME=root
DB_PASSWORD=

GBIF_RATE_LIMIT=60
INATURALIST_RATE_LIMIT=60

HORIZON_ENABLED=false         # Dashboard de jobs: no instalado
```

### Producción Nacional (Objetivo)

```ini
# .env (producción)
QUEUE_CONNECTION=redis        # ← Redis en lugar de BD
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Redis Config
REDIS_HOST=10.0.1.5          # Servidor Redis dedicado
REDIS_PORT=6379
REDIS_PASSWORD=secure_password_here

# Base de datos
DB_CONNECTION=mysql
DB_HOST=db.example.com       # RDS o servidor dedicado
DB_REPLICA_HOST=db-replica.example.com  # Replicación
DB_USERNAME=app_user
DB_PASSWORD=strong_password

# Rate limiting nacional
GBIF_RATE_LIMIT=50           # ← Reducido (múltiples workers)
INATURALIST_RATE_LIMIT=50

# Logging
LOG_CHANNEL=stack
LOG_LEVEL=warning            # ← Reducido (menos noise)

# Sentry para error tracking
SENTRY_LARAVEL_DSN=https://key@sentry.io/project-id

APP_ENV=production
APP_DEBUG=false
```

---

## 2. Cambios en Base de Datos

### Indices Adicionales (Para rendimiento)

```php
// Nueva migración: OptimizeTablesForNationalScale.php

Schema::table('taxa', function (Blueprint $table) {
    // Ya existen:
    // $table->index(['sync_status', 'last_synced_at']);
    // $table->index('gbif_taxon_key');
    // $table->index('inat_taxon_id');
    
    // NUEVOS para nacional:
    $table->fulltext('scientific_name', 'common_name');
    $table->index(['kingdom', 'class', 'family']);  // Filtros taxonomía
    $table->index('conservation_status');            // Filtro amenazadas
    $table->index(['is_native', 'is_endemic']);     // Filtros geográficos
    $table->index('observation_count');              // Ordenamiento popular
});

// Tabla de ocurrencias por región (nueva)
Schema::create('taxon_occurrences_by_region', function (Blueprint $table) {
    $table->id();
    $table->foreignId('taxon_id')->constrained('taxa');
    $table->string('department_code');  // Código DANE
    $table->string('municipality_code')->nullable();
    $table->decimal('latitude', 10, 8);
    $table->decimal('longitude', 11, 8);
    $table->integer('observation_count')->default(0);
    $table->timestamp('last_verified_at')->nullable();
    $table->timestamps();
    
    $table->index(['department_code', 'taxon_id']);
    $table->index(['latitude', 'longitude']);  // Para queries geográficas
});

// Estadísticas pre-calculadas (para dashboards rápidos)
Schema::create('national_statistics', function (Blueprint $table) {
    $table->id();
    $table->integer('total_species_synced');
    $table->integer('total_species_pending');
    $table->json('by_class');  // {Aves: 1923, Insecta: 79245, ...}
    $table->json('conservation_summary');  // {VU: 234, EN: 456, ...}
    $table->timestamp('calculated_at');
    $table->timestamps();
    
    $table->index('calculated_at');
});
```

### Performance Queries

```php
// Optimizar queries frecuentes

// Query 1: Búsqueda nacional (probablemente la más frecuente)
SELECT * FROM taxa
WHERE sync_status = 'synced'
AND (MATCH(scientific_name, common_name) AGAINST('jaguar' IN BOOLEAN MODE))
LIMIT 24
OFFSET 0;
-- Index: FULLTEXT (scientific_name, common_name)
-- Expected: <50ms

// Query 2: Filtrar por clase + amenazadas
SELECT * FROM taxa
WHERE sync_status = 'synced'
AND class = 'Mammalia'
AND conservation_status IN ('VU', 'EN', 'CR')
ORDER BY observation_count DESC
LIMIT 24;
-- Index: (sync_status, class, conservation_status)
-- Expected: <100ms

// Query 3: Estadísticas dashboard
SELECT 
    class,
    COUNT(*) as count,
    SUM(CASE WHEN conservation_status IN ('VU','EN','CR','EW','EX') THEN 1 ELSE 0 END) as threatened
FROM taxa
WHERE sync_status = 'synced'
GROUP BY class;
-- Usar tabla pre-calculada national_statistics
-- Expected: <10ms (caché)
```

---

## 3. Cambios en Configuración de Jobs

### Configurar Horizon (Dashboard de Jobs)

```bash
# Instalar (si es necesario)
composer require laravel/horizon

# Publicar configuración
php artisan horizon:install

# Iniciar en producción
php artisan horizon

# O con supervisor (recomendado para producción)
# Ver: config/horizon.php
```

### config/horizon.php (Para nacional)

```php
'environments' => [
    'production' => [
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['species-sync'],
            'balance' => 'simple',
            'maxProcesses' => 8,              // 8 workers
            'maxTries' => 3,
            'timeout' => 120,
            'sleep' => 5,                    // 5s entre polls (vs 3s local)
        ],
    ],
],

'metrics' => [
    'enabled' => true,
],

'waits' => [
    'redis:default' => 60,
],
```

### Startup Script para Production

```bash
#!/bin/bash
# deploy/start-workers.sh

set -e

cd /var/www/biodiversidad-api

echo "Starting 8 workers for species-sync queue..."

for i in {1..8}; do
    php artisan queue:work \
        --queue=species-sync \
        --connection=redis \
        --sleep=5 \
        --timeout=120 \
        --tries=3 \
        --memory=512 \
        --backoff=30,120,600 \
        --daemon \
        > /tmp/worker-$i.log 2>&1 &
    
    echo "Worker $i started (PID: $!)"
done

echo "All workers started. Monitor with: tail -f /tmp/worker-*.log"
```

---

## 4. Cambios en Rate Limiting

### Actual (Local)

```php
// app/Services/Api/BaseApiService.php (actual)

RateLimiter::for('gbif-api', fn () => Limit::perMinute(60));
RateLimiter::for('inaturalist-api', fn () => Limit::perMinute(60));
```

### Mejorado (Nacional)

```php
// app/Services/Api/BaseApiService.php (nuevo)

RateLimiter::for('gbif-api', function () {
    // Distribuir entre workers
    $workerId = getenv('WORKER_ID') ?? 'default';
    $baseLimit = 50;  // Reducido para ser conservador
    
    return Limit::perMinute($baseLimit)
        ->by('gbif-' . $workerId)
        ->response(function ($request, $limit) {
            $retryAfter = $limit->retryAfter;
            Log::warning("GBIF rate limit exceeded. Retry after: {$retryAfter}s");
            return $this->backoffAndRetry($retryAfter);
        });
});

RateLimiter::for('inaturalist-api', function () {
    $workerId = getenv('WORKER_ID') ?? 'default';
    $baseLimit = 50;
    
    return Limit::perMinute($baseLimit)
        ->by('inat-' . $workerId)
        ->response(function ($request, $limit) {
            $retryAfter = $limit->retryAfter;
            Log::warning("iNaturalist rate limit exceeded. Retry after: {$retryAfter}s");
            return $this->backoffAndRetry($retryAfter);
        });
});

private function backoffAndRetry(int $seconds): void
{
    Log::info("Sleeping for {$seconds}s due to rate limit");
    sleep($seconds);
    // Job retry automático por Laravel
}
```

---

## 5. Cambios en Monitoreo

### Agregar Sentry (Error Tracking)

```bash
# Instalar
composer require sentry/sentry-laravel

# Publicar config
php artisan sentry:publish --dsn=https://key@sentry.io/project
```

### config/sentry.php

```php
'dsn' => env('SENTRY_LARAVEL_DSN'),

'traces_sample_rate' => 0.1,  // 10% de transacciones (no 100%)

'profiles_sample_rate' => 0.1,

'environment' => env('APP_ENV'),

'release' => env('APP_VERSION'),
```

### Logging Mejorado

```php
// En EnrichSpeciesJob

Log::channel('jobs')->info('Enriqueciendo especie', [
    'species' => $this->canonicalName,
    'worker_id' => getenv('WORKER_ID'),
    'timestamp' => now()->toIso8601String(),
]);

Log::channel('jobs')->warning('Especie falló en sincronización', [
    'species' => $this->canonicalName,
    'attempt' => $this->attempts(),
    'error' => $e->getMessage(),
    'next_retry' => $this->backoff[$this->attempts()] ?? 'max_retries',
]);
```

---

## 6. Cambios en API Endpoints

### Agregar Endpoints Nacionales

```php
// routes/api.php

// Stats nacionales (cachéado)
Route::get('/stats', [SpeciesController::class, 'statsNational']);
// GET /api/stats → {total_species: 168493, by_class: {...}}

// Búsqueda nacional (full-text)
Route::get('/species/search', [SpeciesController::class, 'searchNational']);
// GET /api/species/search?q=jaguar → 24 results

// Distribución regional
Route::get('/species/{id}/distribution', [SpeciesController::class, 'distribution']);
// GET /api/species/40151/distribution → {departments: [...]}

// Comparativas nacionales
Route::get('/biodiversity/comparison', [StatsController::class, 'regionalComparison']);
// GET /api/biodiversity/comparison → Colombia vs Perú vs Brazil
```

### Controller Mejorado

```php
// app/Http/Controllers/SpeciesController.php

class SpeciesController extends Controller
{
    public function statsNational()
    {
        return Cache::remember('stats:national', now()->addDay(), function () {
            return [
                'total_species' => Taxa::where('sync_status', 'synced')->count(),
                'total_endemic' => Taxa::where('is_endemic', true)->count(),
                'threatened_species' => Taxa::whereIn('conservation_status', ['VU','EN','CR','EW','EX'])->count(),
                'by_class' => $this->byClass(),
                'by_conservation' => $this->byConservation(),
                'last_updated' => Taxa::where('sync_status', 'synced')->max('last_synced_at'),
            ];
        });
    }
    
    public function searchNational(Request $request)
    {
        $query = $request->input('q');
        $page = $request->input('page', 1);
        
        $results = Taxa::where('sync_status', 'synced')
            ->whereRaw("MATCH(scientific_name, common_name) AGAINST(? IN BOOLEAN MODE)", [$query])
            ->paginate(24, ['*'], 'page', $page);
        
        return response()->json([
            'data' => $results->items(),
            'pagination' => $results->only(['current_page', 'last_page', 'total']),
        ]);
    }
}
```

---

## 7. Cambios en Deployment

### CI/CD Pipeline (GitHub Actions)

```yaml
# .github/workflows/deploy-production.yml

name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      
      - name: Deploy to production server
        run: |
          ssh user@production-server << 'EOF'
          cd /var/www/biodiversidad-api
          git pull origin main
          composer install --no-dev
          php artisan migrate --force
          php artisan cache:clear
          php artisan route:cache
          php artisan config:cache
          
          # Restart workers
          supervisorctl restart species-sync:*
          EOF
```

### Supervisor Config (Manage Workers)

```ini
# /etc/supervisor/conf.d/species-sync.conf

[program:species-sync]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/biodiversidad-api/artisan queue:work --queue=species-sync --sleep=5 --timeout=120 --memory=512
autostart=true
autorestart=true
user=www-data
numprocs=8
redirect_stderr=true
stdout_logfile=/var/log/laravel/worker.log
```

```bash
# Iniciar
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start species-sync:*

# Ver estado
sudo supervisorctl status species-sync:*

# Logs
tail -f /var/log/laravel/worker.log
```

---

## 8. Checklist de Migración Local → Nacional

```
ANTES DE LANZAR A NACIONAL:

Infrastructure:
  [ ] Redis instalado y corriendo en producción
  [ ] RDS/DB replicada configurada
  [ ] Load balancer (nginx) configurado
  [ ] Supervisor instalado
  [ ] Sentry cuenta creada

Code Changes:
  [ ] .env actualizado con valores de producción
  [ ] Migraciones de índices ejecutadas
  [ ] config/horizon.php optimizado
  [ ] Rate limiting ajustado
  [ ] Endpoints nacionales creados

Data:
  [ ] Backfill 100% completado (~170k especies)
  [ ] Todos los jobs procesados
  [ ] Taxa.sync_status = 'synced' para 100%
  [ ] Estadísticas nacionales pre-calculadas

Testing:
  [ ] Load test: 10,000 usuarios simultáneos
  [ ] Rate limit test: GBIF + iNat en paralelo
  [ ] Failover test: Reiniciar worker sin data loss
  [ ] Caché test: Redis funciona correctamente

Deployment:
  [ ] CI/CD pipeline funcional
  [ ] Supervisor configurado con 8 workers
  [ ] Logs centralizados
  [ ] Alertas configuradas (Sentry)
  [ ] Backup automatizado

Launch:
  [ ] Anunciar disponibilidad
  [ ] Monitoreo 24/7
  [ ] Runbook preparado
  [ ] On-call engineer asignado
```

---

## 📊 Resumen: Cambios Principales

| Aspecto | MVP Local | Nacional |
|---------|-----------|----------|
| **Queue** | database | redis |
| **Cache** | file | redis |
| **Workers** | 1 | 8 |
| **Rate Limit** | 60/min | 50/min |
| **Índices** | Básicos | Full-text + geo |
| **Replicación** | No | Sí |
| **Monitoreo** | Logs | Sentry + Horizon |
| **Deployment** | Manual | CI/CD |
| **Especies** | 24 | ~170k |

---

**Tiempo total de escalamiento**: ~2-3 semanas (done bien)
**Equipo necesario**: 1 DevOps + 1 Backend (pueden hacerlo 2 personas juntas)
