# 🇨🇴 Escala Nacional: Hoja de Ruta Implementación

## Fase 1: Validación Local (EN PROGRESO ✅)

```
Objetivo: Validar pipeline con 1 clase taxonómica
├─ ✅ Migración: Done
├─ ⏳ Backfill Aves: En progreso (~156/1900 jobs enqueued)
├─ ⏹️ Worker: Próximo (procesar 156 jobs)
└─ ⏹️ Verificar: Synced > 0

Duración: ~2-3 horas total
```

---

## Fase 2: Catálogo Nacional Completo (PRÓXIMO)

### 2.1 Backfill de Todas las Clases

**Clases prioritarias por orden de tamaño:**

```bash
# 1. Insecta (80k especies) - ~18-24 horas
php artisan species:backfill-catalog --class="Insecta"

# 2. Plantae (50k especies) - ~12-16 horas
php artisan species:backfill-catalog --class="Plantae"

# 3. Fungi (10k especies) - ~2-3 horas
php artisan species:backfill-catalog --class="Fungi"

# 4. Aves (1.9k especies) - Ya hecho ✅
# 5. Amphibia (800 especies)
# 6. Reptilia (600 especies)
# 7. Mammalia (500 especies)
# 8. Arachnida (2k especies)
# 9. Malacostraca (Crustáceos)
# 10. Actinopterygii (Peces)

# Total estimado: ~170k especies Colombia
# Tiempo: ~3-4 días corriendo 24/7 un worker
```

### 2.2 Configuración de Workers Escalada

**En desarrollo local (MVP):**
```bash
# 1 worker, ~150-200 jobs/día
php artisan queue:work --queue=species-sync --sleep=3 --timeout=120
```

**En producción nacional:**
```bash
# Opción A: Múltiples procesos (sin Redis)
php artisan queue:work --queue=species-sync --sleep=5 &
php artisan queue:work --queue=species-sync --sleep=5 &
php artisan queue:work --queue=species-sync --sleep=5 &
# (3-4 workers = ~600-800 jobs/día)

# Opción B: Con Redis (Recomendado para nacional)
redis-server
php artisan queue:work --queue=species-sync --sleep=3 &
php artisan queue:work --queue=species-sync --sleep=3 &
php artisan queue:work --queue=species-sync --sleep=3 &
php artisan queue:work --queue=species-sync --sleep=3 &
# (4 workers + Redis = ~1000-1500 jobs/día)
```

### 2.3 Cronograma Estimado

```
Semana 1 (Nacional):
├─ Día 1: Backfill Insecta (80k → ~400k API calls)
├─ Día 2: Backfill Plantae (50k → ~250k API calls)
├─ Día 3: Backfill Fungi (10k → ~50k API calls)
└─ Día 4: Backfill clases restantes

Semana 2-4 (Workers):
├─ 4 workers corriendo 24/7
├─ Procesando ~150k jobs
├─ Sincronizando ~150k especies
└─ Rate limit GBIF: 60 req/min per worker (safe)

Semana 5:
├─ Catálogo nacional 100% sincronizado
├─ Taxa.sync_status = 'synced' para ~170k especies
├─ Ready para usuarios nacionales
```

---

## Fase 3: Infraestructura Nacional (DURANTE backfill)

### 3.1 Infraestructura Base Necesaria

**En servidor de producción:**

```ini
# .env para producción
QUEUE_CONNECTION=redis      # O SQS en AWS
CACHE_DRIVER=redis
SESSION_DRIVER=redis

# Rate limiting nacional (CRÍTICO)
GBIF_RATE_LIMIT=50          # req/min por worker
INATURALIST_RATE_LIMIT=50   # iNat es más estricto
```

### 3.2 Configurar Redis (Recomendado)

```bash
# Instalación
sudo apt-get install redis-server

# Iniciar
redis-server --daemonize yes

# Verificar
redis-cli ping  # PONG

# En config/database.php - ya está configurado en Laravel
'redis' => [
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
    ],
    'cache' => [...]
]
```

### 3.3 Configurar Scheduler en Crontab

```bash
# En servidor de producción
crontab -e

# Agregar línea:
* * * * * cd /var/www/biodiversidad-api && php artisan schedule:run >> /dev/null 2>&1

# Esto ejecutará:
# - 02:00 UTC: php artisan species:sync-stale (re-enriquecer desactualizadas)
```

### 3.4 Monitoreo & Logging

```bash
# Logs de jobs
tail -f storage/logs/laravel.log | grep "Enriqueciendo\|Error\|sync"

# Redis monitor
redis-cli monitor

# Ver pending jobs en tiempo real
watch -n 5 'redis-cli LLEN queues:species-sync'

# Ver failed jobs
php artisan queue:failed

# Retry failed
php artisan queue:retry all
```

---

## Fase 4: Publicar API Nacional (DESPUÉS de backfill)

### 4.1 Endpoints Nacionales

```php
// routes/api.php

// Listar todas las especies sincronizadas
GET /api/species              // ~170k especies
  ?per_page=24
  ?page=1
  ?q=jaguar              // búsqueda
  ?class=Mammalia        // filtro taxonomía
  ?native=true           // solo nativas
  ?threatened=true       // solo amenazadas
  ?order_by=observations // o random

// Especie individual
GET /api/species/{id}
  Respuesta:
  {
    scientific_name: "Panthera onca",
    common_name: "Jaguar",
    photo: "https://...",
    conservation_status: "VU",
    is_native: true,
    gbif_taxon_key: "5219301",
    inat_taxon_id: "40151",
    distribution: ["Amazonas", "Chocó", "Orinoquia"],
    ...
  }

// Especies en zona geográfica (cache miss → async enrich)
GET /api/species/near
  ?latitude=4.5
  ?longitude=-74.0
  ?radius_km=50
  Respuesta:
  {
    data: [...],
    loading: false,    // o true si aún cargando
    cached: true
  }

// Estadísticas nacionales
GET /api/stats
  Respuesta:
  {
    total_species: 168493,
    total_synced: 168493,
    classes: {
      Aves: 1923,
      Insecta: 79245,
      Plantae: 48932,
      ...
    },
    conservation_status: {
      VU: 234,
      EN: 456,
      CR: 89,
      ...
    }
  }
```

### 4.2 Caché Estratégica Nacional

```php
// Cachear respuestas frecuentes
Cache::remember('stats:national', now()->addDays(1), function () {
    return [
        'total_species' => Taxa::where('sync_status', 'synced')->count(),
        'classes' => Taxa::groupBy('class')->selectRaw('class, count(*) as count')->get(),
    ];
});

// Caché por zona (1 hora)
Cache::remember("zone:{$lat},{$lon},{$radius}", now()->addHours(1), function () {
    return Taxa::nearLocation($lat, $lon, $radius)->get();
});
```

---

## Fase 5: Características Avanzadas (Opcional)

### 5.1 Búsqueda Full-Text Nacional

```sql
-- Índice para búsqueda rápida
ALTER TABLE taxa ADD FULLTEXT INDEX search_idx (scientific_name, common_name);

-- Query optimizada
SELECT * FROM taxa
WHERE MATCH(scientific_name, common_name) AGAINST('jaguar' IN BOOLEAN MODE)
LIMIT 24;
```

### 5.2 Distribución Geográfica

```php
// Agregar ocurrencias por región
Schema::table('taxa', function (Blueprint $table) {
    $table->json('geographic_distribution')->nullable();
    // {"regions": ["Amazonas", "Chocó"], "departments": 5, "municipalities": 23}
});

// Indexar ocurrencias por municipio
GET /api/species/by-municipality/{municipality_code}
```

### 5.3 Datos Comparativos

```php
// Comparar con otros países de Sudamérica
GET /api/biodiversity/south-america
  Respuesta:
  {
    Colombia: {
      species: 168493,
      endemics: 12345,
      threatened: 1234
    },
    Peru: {...},
    Brazil: {...},
    Ecuador: {...}
  }
```

---

## Fase 6: Optimización Nacional (Post-launch)

### 6.1 Análisis de Uso

```php
// Tracking de queries más frecuentes
Log::info('species:search', [
    'query' => $q,
    'results_count' => count($results),
    'response_time_ms' => $responseTime
]);

// Identificar cuellos de botella
SELECT 
    COUNT(*) as count,
    AVG(response_time_ms) as avg_time,
    query
FROM search_log
GROUP BY query
ORDER BY count DESC;
```

### 6.2 Predicción de Carga

```
Usuarios simultáneos: 10,000
├─ 30% buscando: GET /species?q=...
├─ 50% viendo mapa: GET /species/near?lat&lon
├─ 20% filtrando: GET /species?class=...&threatened=true

Requests por segundo: ~1000
├─ Cache hit: <50ms
├─ DB query: 100-200ms
├─ API calls (de jobs): Async (no afecta usuarios)

Capacidad recomendada:
├─ 4 servidores web (load balanced)
├─ 2 servidores Redis
├─ 8 workers de jobs (en servidor separado)
```

### 6.3 Presupuesto de Ancho de Banda

```
API calls a GBIF/iNaturalist:
├─ Backfill: ~170k especies × 2 APIs = 340k calls (1 sola vez)
├─ Mantenimiento (30 días): ~1.7k × 2 = 3.4k calls/mes
├─ Resultado: ~12-15 GB de datos por mes

Storage en BD:
├─ 170k Taxa records: ~200 MB
├─ TaxonApiReference (2 por taxa): ~1.5 GB (raw data)
├─ Total: ~2 GB
```

---

## Checklist: Próximos Pasos Inmediatos

### Semana 1 (Esta semana)

- [ ] **Hoy**: Terminar backfill Aves + procesar jobs locales
- [ ] **Mañana**: Validar que 1000+ especies sincronizadas ✅
- [ ] **Jueves**: Iniciar backfill Insecta (en servidor de test)
- [ ] **Viernes**: Montar Redis en servidor de producción

### Semana 2

- [ ] Continuar backfill de todas las clases (running 24/7)
- [ ] Configurar 4 workers en servidor separado
- [ ] Agregar crontab scheduler
- [ ] Monitorear rate limits (ajustar si es necesario)

### Semana 3-4

- [ ] Backfill terminado (~170k especies)
- [ ] Todos los jobs procesados
- [ ] Taxa con sync_status='synced' = 100%
- [ ] Publicar endpoints nacionales

### Semana 5+

- [ ] Deploy en producción nacional
- [ ] Monitoreo 24/7
- [ ] Marketing/comunicar disponibilidad
- [ ] Feedback de usuarios
- [ ] Optimizaciones

---

## Arquitectura Nacional Final

```
┌─────────────────────────────────────────┐
│         USUARIOS NACIONALES              │
│        (10,000+ simultáneos)             │
└────────────────┬────────────────────────┘
                 │
        ┌────────▼────────┐
        │  Load Balancer  │
        │    (nginx)      │
        └────────┬────────┘
                 │
        ┌────────┴────────┐
        │                 │
┌───────▼────┐   ┌────────▼───────┐
│ App Server │   │  App Server   │
│     #1     │   │      #2       │  (4-8 servers)
└─────┬──────┘   └────────┬───────┘
      │                   │
      └───────┬───────────┘
              │
      ┌───────▼────────┐
      │   Redis Cache  │
      │   + Sessions   │
      └────────────────┘
              │
      ┌───────▼────────┐
      │   MySQL DB     │
      │   170k Taxa    │
      │   1.5GB data   │
      └────────────────┘

┌──────────────────────────────────┐
│   Queue Workers (servidor sep)   │
│   ┌──────────────────────────┐   │
│   │ 4-8 workers species-sync │   │
│   │ Processing: EnrichJob    │   │
│   │ Rate limit: 50 req/min   │   │
│   │                          │   │
│   │ → GBIF API               │   │
│   │ → iNaturalist API        │   │
│   │ → SpeciesMerger          │   │
│   └──────────────────────────┘   │
└──────────────────────────────────┘

┌──────────────────────────────────┐
│      Monitoreo & Logging         │
│ • Sentry (error tracking)        │
│ • New Relic (performance)        │
│ • ELK Stack (logs)               │
│ • Custom dashboard               │
└──────────────────────────────────┘
```

---

## 🎯 Métricas de Éxito Nacional

```
✅ Catálogo Completo
   - 170k+ especies sincronizadas
   - Taxa.sync_status = 'synced' → 100%

✅ Performance
   - Response time API: <100ms (p95)
   - Cache hit rate: >80%
   - Uptime: >99.9%

✅ Datos
   - GBIF data: Actualizado
   - iNaturalist data: Actualizado
   - Sinonimias resueltas: >95%

✅ Usuarios
   - 10,000+ simultáneos soportados
   - Búsquedas por minuto: 1000+
   - Zoom en mapa: Fluido
```

---

## 💡 Decisiones Clave Nacional

| Aspecto | Decisión | Razón |
|---------|----------|-------|
| **Queue Driver** | Redis | Mejor performance + distribuible |
| **Workers** | 4-8 dedicados | No comparten con otras colas |
| **Rate Limit** | 50 req/min | Respeta límites GBIF + iNat |
| **Cache** | Redis 1h | Balance entre freshness y load |
| **DB** | MySQL replicada | High availability |
| **Geografía** | Colombia + frontera | Para expansión regional |

---

## 📞 Soporte & Escalabilidad Futura

```
Fase 1 (Hoy):     Colombia (~170k especies)
Fase 2 (6 meses): Sudamérica (~800k especies)
Fase 3 (1 año):   Trópicos mundiales (~2M especies)
Fase 4 (2 años):  Global (8M+ especies)

Cada fase:
├─ 5-10x datos
├─ Mismo pipeline (escalable)
├─ Infraestructura más grande
└─ Más workers + servidores
```

---

**Siguiente paso concreto**: Terminar validación local (Aves) y reportar cuántas especies sincronizadas ✅
