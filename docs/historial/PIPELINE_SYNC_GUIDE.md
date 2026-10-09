# Pipeline de Sincronización: Guía de Implementación

## 📋 Overview

El sistema de sincronización desacopla completamente la lectura del usuario de las llamadas a APIs externas. Todas las consultas leen de `taxa` ya sincronizada. Los jobs son los únicos que hablan con GBIF e iNaturalist.

```
[GBIF/iNaturalist] 
    ↓
[BackfillNationalCatalogCommand] → Catálogo finito
    ↓
[EnrichSpeciesJob] → Normaliza + guarda datos
    ↓
[Taxa table]
    ↓
[Usuario: GET /species] ← Lee siempre caché
```

## 1. Setup Inicial

### Migración

```bash
php artisan migrate
# Ejecuta: database/migrations/2026_07_01_000000_add_sync_fields_to_taxa_table.php
```

Nuevos campos en `taxa`:
- `last_synced_at` (timestamp)
- `sync_status` enum: pending | syncing | synced | failed
- `sync_attempts` (tinyint)
- `gbif_taxon_key` (indexed)
- `inat_taxon_id` (indexed)

### Configurar cola de workers

**En tu `.env`:**
```env
QUEUE_CONNECTION=database   # o redis, sqs, etc.
```

**Crear tabla de jobs (si usas database driver):**
```bash
php artisan queue:table
php artisan migrate
```

### Configurar el scheduler

En tu servidor, agregar a crontab:
```bash
* * * * * cd /ruta/a/biodiversidad-api && php artisan schedule:run >> /dev/null 2>&1
```

Esto ejecutará los comandos programados en `routes/console.php` cada minuto (Laravel detecta cuáles deben correr).

## 2. Backfill Nacional

**Primera vez (llena el catálogo):**

```bash
php artisan species:backfill-catalog
# o con opciones:
php artisan species:backfill-catalog --class="Aves" --limit=100 --page=1
```

Esto:
1. Pagina por clase taxonómica (Aves, Reptilia, Mammalia, etc.)
2. Obtiene lista de especies de GBIF para Colombia
3. Enqueue un `EnrichSpeciesJob` para cada especie
4. Continúa sin bloquear aunque una llamada a GBIF sea lenta

**Luego, procesa la cola:**

```bash
php artisan queue:work --queue=species-sync --sleep=3 --tries=3 --max-jobs=200
```

Esto:
- Toma jobs de la cola `species-sync`
- Intenta 3 veces con backoff: 30s, 2min, 10min
- Procesa máximo 200 jobs antes de reiniciarse (limpia memoria)
- Duerme 3s entre intentos si la cola está vacía

**Tiempo estimado:**
- ~80k especies × 2 requests (GBIF + iNat) ≈ 160k requests
- Rate limit GBIF: ~60 req/min (no oficial, pero es seguro)
- iNat: ~60 req/min (documentado)
- **Total: ~2-3 días corriendo un worker continuo**

Con 2 workers: ~1.5 días.

## 3. Mantenimiento Automático

**Se ejecuta diariamente a las 02:00 UTC:**

```bash
php artisan species:sync-stale --days=30
```

Esto:
- Encuentra todas las especies con `last_synced_at < now() - 30 días`
- Enqueue un `EnrichSpeciesJob` para cada una
- Los workers procesan en background

**Para forzar ahora (testing):**
```bash
php artisan species:sync-stale --days=0
```

## 4. Flujo de Usuario: Cache Miss → Auto Sync

**Escenario:**
1. Usuario consulta zona nueva donde aún no hay datos indexados
2. Endpoint: `GET /species?latitude=4.5&longitude=-74.0&radius_km=50`

**Qué pasa internamente:**

```php
// app/Services/TaxonService::getSpeciesNearLocation()

// Busca especies sincronizadas en esa zona
$query = Taxa::where('sync_status', 'synced');

// Si está vacío:
if ($paginator->total() === 0 && $lat !== null) {
    SyncRegionOccurrencesJob::dispatch($lat, $lon, $radiusKm);
    
    return [
        'data' => [],
        'loading' => true,
        'message' => 'Estamos cargando...'
    ];
}
```

**Frontend muestra:**
- Primera visita: "Cargando datos de tu zona..." (vacío)
- Las siguientes visitas: datos completos (el job ya corrió)

## 5. Estructura de Jobs

### EnrichSpeciesJob

**Entrada:** nombre científico normalizado
**Salida:** Taxa + TaxonApiReference en BD

```php
// Único (no ejecuta 2 veces el mismo nombre en paralelo)
public function middleware(): array
{
    return [new WithoutOverlapping("enrich-species:{$this->canonicalName}")];
}

// Intenta 3 veces
public $tries = 3;
public $backoff = [30, 120, 600];

// Procesa:
// 1. GBIF::searchTaxon()
// 2. iNaturalist::searchTaxon()
// 3. SpeciesMerger::merge()
// 4. Guarda en Taxa + TaxonApiReference
```

### SyncRegionOccurrencesJob

**Entrada:** lat, lon, radiusKm
**Salida:** Indexa ocurrencias de especies conocidas en esa zona

```php
// Lightweight — solo consulta GBIF y mapea contra catálogo existente
// No enriquece nuevas especies (eso ya pasó en backfill)
// Solo marca "esta especie está en esta zona"
```

## 6. ¿Cómo Mergear GBIF + iNaturalist?

Ver [SpeciesMerger.php](app/Services/SpeciesMerger.php):

- **GBIF es autoritativo para:** taxonomía linneana, estado de conservación
- **iNaturalist es mejor para:** fotos, nombres comunes, observaciones, establishment status

**Ejemplo:**
```php
$merged = $merger->merge(
    ['success' => true, 'data' => $gbifData],
    ['success' => true, 'data' => $inatData]
);

// Resultado:
[
    'scientificName' => 'Panthera onca', // GBIF
    'commonName' => 'Jaguar',            // iNat
    'defaultPhoto' => '...',             // iNat (mejor calidad)
    'conservationStatus' => 'VU',        // GBIF
    'isNative' => true,                  // iNat
    'gbifTaxonKey' => '5219301',
    'inatTaxonId' => '40151',
]
```

## 7. Monitorear Progreso

### Logs

```bash
# Ver logs de jobs
tail -f storage/logs/laravel.log | grep "Enriqueciendo"

# Contar especies sincronizadas
php artisan tinker
>>> App\Models\Taxa::where('sync_status', 'synced')->count();
=> 45230
```

### Estado de la BD

```sql
SELECT 
    sync_status, 
    COUNT(*) as count,
    MAX(last_synced_at) as last_sync
FROM taxa
GROUP BY sync_status;

-- Esperado después de backfill:
-- synced       | 45230 | 2026-07-01 03:45
-- pending      | 0     | null
-- syncing      | 0     | null
-- failed       | 12    | 2026-07-01 02:10
```

## 8. Troubleshooting

### Las especies no se enriquecen

1. **¿El worker está corriendo?**
   ```bash
   ps aux | grep queue:work
   ```

2. **¿Hay jobs en la cola?**
   ```bash
   php artisan queue:retry failed # Retry failed jobs
   php artisan queue:failed       # Ver failed
   ```

3. **¿Rate limit de GBIF?**
   - Revisa logs: `"Rate limit alcanzado para gbif"`
   - Baja `--sleep` en el worker a 5-10s

### El usuario ve "Cargando..." siempre

- El job está fallando silenciosamente
- Revisa logs de `SyncRegionOccurrencesJob`
- Verifica que GbifService::searchOccurrencesByRegion funciona

### Sincronización desactualizadas

- El scheduler no está activo: verifica que crontab está bien
- O el comando falla: corre manualmente `php artisan species:sync-stale`

## 9. Comandos Útiles (Operación)

```bash
# Iniciar workers (producción)
php artisan queue:work --queue=species-sync --daemon --tries=3

# Ver failed jobs
php artisan queue:failed

# Retry todos los failed
php artisan queue:retry all

# Clear queue
php artisan queue:clear

# Limpiar jobs viejos (si usas BD)
php artisan queue:prune-batches
php artisan queue:prune-failed

# Contar especies por status
php artisan tinker
>>> App\Models\Taxa::groupBy('sync_status')->selectRaw('sync_status, count(*) as cnt')->get();
```

## 10. Arquitectura en Escala Nacional

### Catálogo Nacional
- **Finito:** ~50k-100k especies de Colombia
- **Backfill:** 2-3 días, una sola vez
- **Mantenimiento:** 30 min/día a las 02:00

### Ocurrencias Geográficas
- **Disparado por zona nueva:** Async, no bloquea usuario
- **Lightweight:** Solo índice, sin enriquecer especies nuevas

### Asincronía Total
- Usuario **nunca espera** por GBIF/iNaturalist
- Todo es background con notificación UI clara

### Escalabilidad
- ¿1 millón de usuarios? Workers + Redis queue
- ¿Muchas zonas nuevas? Aumenta workers de `species-sync`
- ¿GBIF lento? Rate limiter automático + retry

---

**Next steps:**
1. Corre: `php artisan migrate`
2. Corre: `php artisan species:backfill-catalog --class="Aves"` (test con 1 clase)
3. Corre: `php artisan queue:work --queue=species-sync`
4. Verifica: `php artisan tinker → App\Models\Taxa::count()`
5. Deploys scheduler en crontab
