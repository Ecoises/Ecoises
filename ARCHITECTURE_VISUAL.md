# Arquitectura Visual: Pipeline de Sincronización

## Flujo Completo

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          USUARIO FINAL                                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                    ↓
                    GET /species?latitude=4.5&longitude=-74.0
                                    ↓
┌─────────────────────────────────────────────────────────────────────────────┐
│                       TaxonService                                           │
│  getSpeciesNearLocation(filters)                                            │
├─────────────────────────────────────────────────────────────────────────────┤
│  1. Taxa::where('sync_status', 'synced')->get()                            │
│  2. IF resultado.count() = 0:                                              │
│     └─ Dispatch SyncRegionOccurrencesJob($lat, $lon, $radius)              │
│     └─ Return: {data: [], loading: true}                                  │
│  3. ELSE:                                                                   │
│     └─ Return: {data: [...especie, foto, status conservación...]}          │
└─────────────────────────────────────────────────────────────────────────────┘
                                    ↓
                        ┌───────────┴───────────┐
                        ↓                       ↓
              (Datos disponibles)     (Cache Miss)
                        ↓                       ↓
              [Usuarios felices]    ┌─────────────────────────────────────┐
                                    │  Redis/Database Queue               │
                                    │  - SyncRegionOccurrencesJob         │
                                    │  - EnrichSpeciesJob                 │
                                    │  - (otros jobs de la app)           │
                                    └─────────────────────────────────────┘
                                                  ↓
                            ┌─────────────────────────────────────────────────┐
                            │      Queue Worker (Dedicado: species-sync)      │
                            │  php artisan queue:work --queue=species-sync    │
                            └─────────────────────────────────────────────────┘
                                        ↓
                    ┌───────────────────┼───────────────────┐
                    ↓                   ↓                   ↓
          ┌──────────────────┐ ┌──────────────────┐ ┌──────────────────┐
          │  EnrichSpeciesJob│ │  SyncRegionOcc..│ │   (Otros jobs)   │
          │                  │ │                  │ │                  │
          │ 1. searchTaxon() │ │ 1. searchOccur.. │ │                  │
          │    GBIF          │ │    GBIF          │ │                  │
          │ 2. searchTaxon() │ │ 2. mapea contra  │ │                  │
          │    iNaturalist   │ │    Taxa existente│ │                  │
          │ 3. Merge         │ │                  │ │                  │
          │    normaliza     │ │ Tiempo: ~60s     │ │                  │
          │ 4. Save Taxa +   │ │ Intentos: 2      │ │                  │
          │    TaxonApiRef   │ │                  │ │                  │
          │                  │ │ (No dispara      │ │                  │
          │ Tiempo: ~120s    │ │  enriquecimiento)│ │                  │
          │ Intentos: 3      │ │                  │ │                  │
          │ Backoff: exp     │ │                  │ │                  │
          └──────────────────┘ └──────────────────┘ └──────────────────┘
                    ↓
        ┌───────────────────────────────┐
        │  Taxa Table                   │
        │  ┌──────────────────────────┐ │
        │  │ id: 1                    │ │
        │  │ scientific_name: "..."   │ │
        │  │ sync_status: 'synced' ✓  │ │
        │  │ last_synced_at: now()    │ │
        │  │ gbif_taxon_key: "..."    │ │
        │  │ inat_taxon_id: "..."     │ │
        │  │ (foto, conservación...)  │ │
        │  └──────────────────────────┘ │
        │           +                     │
        │  ┌──────────────────────────┐ │
        │  │ TaxonApiReference        │ │
        │  │ ├─ api_source: gbif      │ │
        │  │ ├─ data: {raw gbif}      │ │
        │  │ └─ api_source: inat      │ │
        │  │    data: {raw inat}      │ │
        │  └──────────────────────────┘ │
        └───────────────────────────────┘
```

---

## Estados de una Especie

```
┌─────────────┐
│   pending   │ (inicial, nunca procesada)
└──────┬──────┘
       ↓
    [Job enqueued]
       ↓
┌─────────────┐         ┌────────────┐
│   syncing   │ ────→ │ max retries │
└──────┬──────┘ ✗     └────────────┘
       ↓ ✓                  ↓
    ┌──────────┐      ┌──────────┐
    │  synced  │      │  failed  │
    └──────────┘      └──────────┘
    (pronta leer)     (usuario no ve)
       ↓
   [30+ días]
       ↓
  [Re-enrich]
       ↓
    (repeats)
```

---

## Backfill Nacional: Arquitectura

```
START: php artisan species:backfill-catalog

┌─────────────────────────────────────────────────────┐
│ BackfillNationalCatalogCommand                      │
│ Itera por clase taxonómica                          │
├─────────────────────────────────────────────────────┤
│ for class in ['Aves', 'Reptilia', 'Mammalia', ...]│
│   page = 0                                          │
│   do:                                               │
│     ├─ GBIF::getSpeciesList(country='CO',          │
│     │                       class='Aves',          │
│     │                       page=0, per_page=100)  │
│     ├─ Loop cada especie:                          │
│     │   └─ Taxa.firstOrCreate()                    │
│     │   └─ IF not synced:                          │
│     │      └─ EnrichSpeciesJob::dispatch()         │
│     │         → onQueue('species-sync')            │
│     ├─ page++                                       │
│   while has_more                                    │
│                                                     │
│ Output: ~80k jobs enqueued                          │
└─────────────────────────────────────────────────────┘
         ↓
    [Enqueue output]
    "Enqueued: 45230 species"
         ↓
    php artisan queue:work --queue=species-sync

    [Worker procesa continuo]
    [~2-3 días para 80k especies]
    [Taxa.sync_status → 'synced' cuando complete]
```

---

## Scheduler (Maintenance Loop)

```
┌──────────────────────────────────────┐
│    Crontab: * * * * *                │
│    (cada minuto)                     │
└──────────────────────────────────────┘
         ↓
┌──────────────────────────────────────┐
│ php artisan schedule:run              │
│ (chequea qué tareas deben correr)    │
└──────────────────────────────────────┘
         ↓
┌──────────────────────────────────────┐
│ IF hora = 02:00 UTC:                 │
│   php artisan species:sync-stale     │
│   (solo una vez/día)                 │
└──────────────────────────────────────┘
         ↓
┌──────────────────────────────────────┐
│ SyncStaleSpeciesCommand              │
│                                      │
│ SELECT taxa WHERE                    │
│   last_synced_at < now() - 30 días   │
│   OR last_synced_at IS NULL          │
│                                      │
│ FOR EACH:                            │
│   EnrichSpeciesJob::dispatch()       │
│   → onQueue('species-sync')          │
└──────────────────────────────────────┘
         ↓
    [Jobs enqueued]
    "~500-5000 jobs/día"
         ↓
    [Worker procesa]
    [Taxa.last_synced_at = now()]
```

---

## Rate Limiting (Protección de APIs)

```
┌─────────────────────────────────────┐
│    EnrichSpeciesJob / Worker        │
└─────────────────────────────────────┘
         ↓
    [Request GBIF]
         ↓
┌─────────────────────────────────────┐
│ BaseApiService::makeRequest()        │
│                                     │
│ RateLimiter::tooManyAttempts()?     │
│   IF yes:                           │
│     └─ sleep($seconds)              │
│        Log.warning("Rate limited")  │
│     └─ continue                     │
│   ELSE:                             │
│     └─ RateLimiter::hit()           │
│     └─ HTTP request → GBIF          │
│                                     │
│ Default: 60 req/min (safe)          │
└─────────────────────────────────────┘
         ↓
    [iNaturalist API]
    (same protection)
```

---

## Ejemplo: Ciclo Vida de una Especie

### Día 0: Backfill
```
User: php artisan species:backfill-catalog --class="Aves"
  ↓
GBIF: GET /species/match?name=Panthera onca
  ↓
BackfillNationalCatalogCommand: enqueue EnrichSpeciesJob
  ↓
Taxa: create(scientific_name: 'Panthera onca', sync_status: 'pending')
```

### Día 0-1: Enriquecimiento (Worker)
```
EnrichSpeciesJob(canonicalName: 'Panthera onca')
  ├─ Taxa.update(sync_status: 'syncing')
  ├─ GBIF::searchTaxon('Panthera onca') → gbifData
  ├─ iNaturalist::searchTaxon('Panthera onca') → inatData
  ├─ SpeciesMerger::merge(gbifData, inatData) → canonical
  ├─ Taxa.update({
  │    scientific_name: 'Panthera onca',
  │    common_name: 'Jaguar',
  │    conservation_status: 'VU',
  │    is_native: true,
  │    gbif_taxon_key: '5219301',
  │    inat_taxon_id: '40151',
  │    sync_status: 'synced',
  │    last_synced_at: now()
  │  })
  └─ TaxonApiReference.create(
       api_source: 'gbif',
       data: {gbifData}
     )
       TaxonApiReference.create(
       api_source: 'inaturalist',
       data: {inatData}
     )
```

### Día 1-31: Available to Users
```
GET /species?q=jaguar
  ↓
Taxa::where('sync_status', 'synced')
     ->where('common_name', 'like', '%jaguar%')
  ↓
Response: {
  scientific_name: 'Panthera onca',
  common_name: 'Jaguar',
  photo: 'https://...inat.../photo.jpg',
  conservation_status: 'VU',
  ...
}
```

### Día 31+: Mantenimiento
```
02:00 UTC: Scheduler triggers species:sync-stale
  ↓
SyncStaleSpeciesCommand: SELECT taxa WHERE last_synced_at < 30 días ago
  ↓
Encuentra: Panthera onca (31 días sin sync)
  ↓
Enqueue: EnrichSpeciesJob (repite ciclo)
  ↓
Taxa.last_synced_at = now()
```

---

## Conteo de Requestsuarios Finales ≠ Requests a APIs

```
Usuario hace 100 request: GET /species
  ↓
Taxa table: 100 x SELECT (DB queries, no API calls)
  ↓
→ 0 requests a GBIF/iNaturalist

[Async, background]
EnrichSpeciesJob dispara: 1 GBIF + 1 iNaturalist
  ↓
SyncRegionOccurrencesJob dispara: 1 GBIF
  ↓
→ ~2-3 requests/job

Resultado:
  • 100 usuarios felices
  • ~2-3 API requests (no 200-300)
```

---

## Configuración Recomendada

### Small (MVP: 1 clase taxonómica)
```bash
# 1 worker
php artisan queue:work --queue=species-sync --sleep=3

# ~1.5 horas para Aves (~1900 especies)
```

### Medium (Nacional: ~50k especies)
```bash
# 2-3 workers
php artisan queue:work --queue=species-sync --sleep=3 &
php artisan queue:work --queue=species-sync --sleep=3 &

# ~1.5-2 días
```

### Large (Futuro: +100k especies, Sudamérica)
```bash
# 4-8 workers + Redis
php artisan queue:work --queue=species-sync --sleep=5 &
(x8)

# ~12-18 horas
```

---

**Nota:** Todos los diagramas usan ASCII simple para legibilidad. Para diagramas más complejos, considera PlantUML o Mermaid.
