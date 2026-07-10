# Resumen de Implementación: Pipeline de Sincronización

## ✅ Archivos Modificados

### 1. **app/Models/Taxa.php**
- ✅ Agregados campos a `$fillable`:
  - `last_synced_at`
  - `sync_status`
  - `sync_attempts`
  - `gbif_taxon_key`
  - `inat_taxon_id`
- ✅ Agregados a `$casts`: `last_synced_at` → datetime
- ✅ Corregido: duplicada línea `$enriched = $this->toArray();` (eliminada)
- ✅ Corregido: `$ref = $this->apiReferences->first()` → `$ref = $this->apiReferences->firstWhere('api_source', 'inaturalist')`
  - Ahora busca explícitamente referencia de iNaturalist, no asume que es la primera

### 2. **app/Services/Api/GbifService.php**
- ✅ Agregado método: `searchOccurrencesByRegion(float $lat, float $lon, float $radiusKm)`
  - Usa GBIF `/occurrence/search` con facets
  - Retorna lista de especies en una región geográfica

### 3. **app/Services/TaxonService.php**
- ✅ Actualizado: `getSpeciesNearLocation(array $filters)`
  - Ahora filtra solo especies con `sync_status = 'synced'`
  - Agregada detección de cache miss (cuando zona está vacía)
  - En cache miss, dispara `SyncRegionOccurrencesJob` async
  - Retorna `'loading' => true` y mensaje educativo al usuario

### 4. **routes/console.php**
- ✅ Agregado schedule para `species:sync-stale`
  - Ejecuta diariamente a las 02:00 UTC
  - Re-enriquece especies con más de 30 días sin actualizar

---

## 📁 Archivos Creados

### Migraciones

**database/migrations/2026_07_01_000000_add_sync_fields_to_taxa_table.php**
- Nuevos campos: `last_synced_at`, `sync_status`, `sync_attempts`, `gbif_taxon_key`, `inat_taxon_id`
- Índice compuesto: `[sync_status, last_synced_at]`

### Servicios

**app/Services/SpeciesMerger.php** (NUEVA)
- Normaliza datos de GBIF + iNaturalist
- Resuelve sinonimias y nombre canónico
- Elige mejor foto, status de conservación, etc.
- Retorna record canónico para guardar en Taxa

### Jobs

**app/Jobs/EnrichSpeciesJob.php** (NUEVA)
- Enriquece una especie individual desde GBIF + iNat
- Middleware `WithoutOverlapping` evita duplicados
- 3 reintentos con backoff exponencial (30s, 2min, 10min)
- Guarda datos normalizados en Taxa + TaxonApiReference
- Sincronización de cada especie: **una sola vez**, luego solo mantenimiento

**app/Jobs/SyncRegionOccurrencesJob.php** (NUEVA)
- Lightweight: indexa ocurrencias de región
- Dispara cuando usuario consulta zona nueva (cache miss)
- NO enriquece especies nuevas (eso ya pasó en backfill)
- 2 reintentos, timeout 60s

### Comandos

**app/Console/Commands/BackfillNationalCatalogCommand.php** (NUEVA)
- Llena catálogo nacional paginando por clase taxonómica
- Una sola ejecución: `php artisan species:backfill-catalog`
- Respeta rate limits de GBIF
- Manejable: procesa Aves, Reptilia, etc. de forma independiente

**app/Console/Commands/SyncStaleSpeciesCommand.php** (NUEVA)
- Re-enriquece especies desactualizadas
- Ejecuta automáticamente cada día a las 02:00
- Se puede forzar: `php artisan species:sync-stale --days=0`

### Documentación

**PIPELINE_SYNC_GUIDE.md**
- Guía completa de setup, operación, troubleshooting
- Estimaciones de tiempo
- Comandos útiles
- Flujos de datos visuales

---

## 🔄 Flujos Implementados

### Flujo 1: Backfill Nacional (Vez Inicial)

```
php artisan species:backfill-catalog
    ↓
Pagina por clase taxonómica (Aves, Reptilia, ...)
    ↓
GBIF /occurrence/search + facet=speciesKey
    ↓
Enqueue EnrichSpeciesJob por cada especie
    ↓
php artisan queue:work --queue=species-sync
    ↓
EnrichSpeciesJob:
  - GBIF ::searchTaxon()
  - iNat ::searchTaxon()
  - SpeciesMerger::merge()
  - Save Taxa + TaxonApiReference
    ↓
Taxa.sync_status = 'synced'
```

**Tiempo:** ~2-3 días (finito)

### Flujo 2: Mantenimiento Diario

```
[02:00 UTC] php artisan species:sync-stale
    ↓
Encuentra: last_synced_at < now() - 30 días
    ↓
Enqueue EnrichSpeciesJob para cada una
    ↓
Workers procesan en background
    ↓
Taxa.last_synced_at = now()
```

**Tiempo:** ~30 min/día (incremental)

### Flujo 3: Cache Miss (Usuario en Zona Nueva)

```
GET /species?latitude=4.5&longitude=-74.0
    ↓
TaxonService::getSpeciesNearLocation()
    ↓
SELECT * FROM taxa WHERE sync_status='synced'
    ↓
Result: VACÍO
    ↓
Dispatch SyncRegionOccurrencesJob(4.5, -74.0, 50)
    ↓
Return: {data: [], loading: true, message: "Cargando..."}
    ↓
[Background] SyncRegionOccurrencesJob:
  - GBIF::searchOccurrencesByRegion()
  - Mapea contra Taxa existente
    ↓
[Next request] Ya hay datos
```

**UX:** Primera visita → "Cargando", siguiente → datos completos

---

## 🧪 Verificación Post-Instalación

### Paso 1: Migración
```bash
php artisan migrate
# Verifica que agregó columnas a Taxa
php artisan tinker
>>> Schema::getColumns('taxa');
```

### Paso 2: Test Backfill (1 clase)
```bash
php artisan species:backfill-catalog --class="Aves" --limit=10 --page=1
# Debe enqueue ~10 jobs
php artisan queue:work --queue=species-sync --timeout=120
# Procesa jobs
```

### Paso 3: Verificar BD
```bash
php artisan tinker
>>> App\Models\Taxa::where('sync_status', 'synced')->count();
# Debe mostrar N > 0
>>> App\Models\Taxa::pluck('sync_status')->unique();
# Debe mostrar: ["synced", "failed", "pending"]
```

### Paso 4: Test Cache Miss
```bash
# Hacer request a zona sin datos
GET /species?latitude=-4.5&longitude=-74.0
# Debe retornar: {data: [], loading: true}
# Debe loguear: "Cache miss en zona nueva, encolando sincronización"

# Procesar job
php artisan queue:work --queue=species-sync --timeout=60

# Segunda request
GET /species?latitude=-4.5&longitude=-74.0
# Debe retornar: {data: [...], loading: false}
```

### Paso 5: Scheduler
```bash
# Verifica que se agregó a routes/console.php
grep -n "species:sync-stale" routes/console.php
# Debe mostrar: Schedule::command('species:sync-stale')->dailyAt('02:00')
```

---

## 📊 Estadísticas de Uso

### Catálogo Nacional
- **Especies:** ~50k-100k
- **Backfill:** ~2-3 días (1 worker)
- **Mantenimiento:** ~30 min/día

### Por Clase (estimadas para Colombia)
| Clase | Especies | Tiempo Backfill |
|-------|----------|-----------------|
| Aves | 1,900 | 1-2 horas |
| Mammalia | 500 | 20-30 min |
| Reptilia | 600 | 30-45 min |
| Amphibia | 800 | 45-60 min |
| Insecta | 80,000 | 8-12 horas |
| Plantae | 50,000 | 5-8 horas |

**Con 2 workers:** ÷ 2
**Con 4 workers:** ÷ 3-3.5 (overhead de coordinación)

---

## 🚀 Deploy Checklist

- [ ] Corre migración: `php artisan migrate`
- [ ] Deploy comando: `BackfillNationalCatalogCommand`
- [ ] Deploy jobs: `EnrichSpeciesJob`, `SyncRegionOccurrencesJob`
- [ ] Deploy servicios: `SpeciesMerger`
- [ ] Deploy comandos: `SyncStaleSpeciesCommand`
- [ ] Actualizar rutas/console.php con scheduler
- [ ] Actualizar TaxonService para cache miss
- [ ] Configura worker: `php artisan queue:work --queue=species-sync --daemon`
- [ ] Agregar a crontab: `* * * * * php artisan schedule:run`
- [ ] Test: `php artisan species:backfill-catalog --class="Aves"`
- [ ] Monitor: `tail -f storage/logs/laravel.log`

---

## 💡 Decisiones de Diseño

1. **Backfill separado de mantenimiento**
   - Backfill: Todo de una vez (finito, acotado)
   - Mantenimiento: Incremental diario (eficiente)

2. **GBIF > iNaturalist para taxonomía**
   - GBIF es más riguroso con clasificación linneana
   - iNaturalist es mejor para observaciones + fotos

3. **Caché miss dispara async**
   - Usuario nunca espera
   - UX honesta: "Cargando..."
   - Segunda visita: datos completos

4. **Rate limiting explícito**
   - No confíes en "pocos workers"
   - Protege la relación con APIs externas

5. **WithoutOverlapping en EnrichSpeciesJob**
   - Evita procesar la misma especie 2+ veces en paralelo
   - Importante si hay reintento + nuevo dispatch

---

**Última actualización:** 2026-07-01
**Versión:** v1.0 (MVP)
