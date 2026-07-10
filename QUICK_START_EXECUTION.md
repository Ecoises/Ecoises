# ⚡ EJECUCIÓN RÁPIDA: Pipeline Completo

## 🎯 Paso 1: Inicia el Backfill (Terminal 1)

```bash
cd c:\laragon\www\biodiversidad-api

# Ejecuta UNA SOLA VEZ para cada clase
php artisan species:backfill-catalog --class="Aves"
# Enqueue ~1900 jobs de Aves

# Cuando termine, hacer otra clase:
php artisan species:backfill-catalog --class="Reptilia"
# etc.
```

**Qué ves:**
- `✓ Enqueued: 1900 | Total: 1900` ← Significa que enqueó 1900 jobs
- Los números suben cada ~6-7 segundos (GBIF rate limit)
- Termina cuando ve: `✅ Backfill completado`

**Cierra:** Presiona `CTRL+C` cuando termina

---

## 🎯 Paso 2: Ver Jobs en Cola (Terminal 2 - Opcional, para monitorear)

```bash
cd c:\laragon\www\biodiversidad-api

# Ver cantidad de jobs pendientes
php artisan queue:retry all

# O ver directo en BD:
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; echo DB::table('jobs')->count() . ' jobs en cola';"
```

---

## 🎯 Paso 3: Procesa los Jobs (Terminal 3)

```bash
cd c:\laragon\www\biodiversidad-api

# Inicia worker que procesa jobs
php artisan queue:work --queue=species-sync --sleep=3 --timeout=120 --tries=3 --max-jobs=0
```

**Qué ves:**
```
[2026-07-01 23:45:00] Processing: App\Jobs\EnrichSpeciesJob
[2026-07-01 23:45:15] ✅ Especie enriquecida exitosamente: Panthera onca
[2026-07-01 23:45:30] Processing: App\Jobs\EnrichSpeciesJob
[2026-07-01 23:45:45] ✅ Especie enriquecida exitosamente: Eira barbara
...
```

**Duración:** ~1900 jobs × 15s promedio = ~8 horas (puede variar)

**Cierra:** Presiona `CTRL+C` cuando termines (o déjalo corriendo en background)

---

## 🎯 Paso 4: Ver Resultados (Terminal 4 - Mientras procesa jobs)

```bash
cd c:\laragon\www\biodiversidad-api

# Ver cantidad de especies SINCRONIZADAS
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$synced = DB::table('taxa')->where('sync_status', 'synced')->count(); echo \"✅ Synced: \$synced\n\";"

# Ver estado breakdown
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$synced = DB::table('taxa')->where('sync_status', 'synced')->count();
\$pending = DB::table('taxa')->where('sync_status', 'pending')->count();
\$syncing = DB::table('taxa')->where('sync_status', 'syncing')->count();
\$failed = DB::table('taxa')->where('sync_status', 'failed')->count();
echo \"
✓ Synced: \$synced
⏳ Pending: \$pending
🔄 Syncing: \$syncing
✗ Failed: \$failed
\";
"

# Ver jobs en cola (mientras procesa)
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; echo 'Jobs: ' . DB::table('jobs')->count() . PHP_EOL;"
```

**Ejecuta estos comandos cada ~5 minutos para ver progreso**

---

## 📊 FLUJO COMPLETO RESUMIDO

```
TERMINAL 1: php artisan species:backfill-catalog --class="Aves"
            ↓
            (Enqueue 1900 jobs)
            ↓
            CTRL+C cuando termina

TERMINAL 3: php artisan queue:work --queue=species-sync
            ↓
            (Procesa los 1900 jobs)
            ↓
            Cada job: GBIF + iNat + Merge + Save
            ↓
            Déjalo corriendo 8+ horas

TERMINAL 4: Cada 5 minutos: php -r "...especies_synced..."
            ↓
            Monitorea progreso
```

---

## 🔍 VER LOGS EN TIEMPO REAL

```bash
# Terminal separada - monitorea errores mientras procesa
cd c:\laragon\www\biodiversidad-api

tail -f storage/logs/laravel.log | grep -i "error\|failed\|rate"
# Si ves muchos "Error", hay un problema
# Si ves "Rate limit", está todo bien (retry automático)
```

---

## ✅ CÓMO SABER QUE TODO VA BIEN

| Indicador | Significa |
|-----------|-----------|
| `✓ Enqueued: 1900` | Jobs se están enqueando correctamente ✅ |
| `✅ Especie enriquecida exitosamente` | Un job se procesó exitosamente ✅ |
| `Synced: 150` después de 10 minutos | Workers funcionan bien ✅ |
| `Rate limit alcanzado` | Normal, retry automático ✅ |
| `GBIF error` en logs | GBIF respondió con error, retry automático ✅ |

---

## ❌ CÓMO SABER QUE HAY UN PROBLEMA

| Síntoma | Solución |
|---------|----------|
| `No jobs en cola después de backfill` | Algo falló en enqueue. Ver logs. |
| `Synced: 0 después de 30 minutos` | Worker no está corriendo bien. Revisar errors. |
| `SpeciesMerger::merge error` en logs | Mismatch de datos. Ya arreglado. |
| `Connection refused` | Redis no está corriendo (para producción). |
| Mismo trabajo se repite | Worker se crasheó. Reinicia con CTRL+C y nuevamente. |

---

## 🛑 PARAR PROCESOS

```bash
# En la terminal del proceso
CTRL+C

# O desde otra terminal, mata todos los php.exe
taskkill /IM php.exe /F
```

---

## 📈 ESTIMACIONES DE TIEMPO

```
Backfill Aves:        ~2-3 minutos (enqueue)
Procesar 1900 jobs:   ~8-10 horas (worker)
                      ↓
Total para 1 clase:   ~8-10 horas

Si quieres todas las clases:
Aves:       8-10h
Reptilia:   3-4h
Mammalia:   2-3h
Amphibia:   3-4h
Insecta:    40-50h ← Mucho trabajo
Plantae:    20-30h ← Mucho trabajo
---
TOTAL:      ~75-100 horas = 3-4 días corriendo 24/7
```

---

## 🚀 FORMA MÁS RÁPIDA (Producción)

Si tienes 4 workers corriendo en paralelo:

```bash
# Terminal A: Worker 1
php artisan queue:work --queue=species-sync

# Terminal B: Worker 2
php artisan queue:work --queue=species-sync

# Terminal C: Worker 3
php artisan queue:work --queue=species-sync

# Terminal D: Worker 4
php artisan queue:work --queue=species-sync
```

**Resultado:** ~4x más rápido = 20-30 horas total

---

## 📝 SCRIPT PARA AUTOMATIZAR (Bash/PowerShell)

```powershell
# run-full-pipeline.ps1
$classes = @("Aves", "Reptilia", "Mammalia", "Amphibia")

foreach ($class in $classes) {
    Write-Host "🚀 Backfilling $class..."
    php artisan species:backfill-catalog --class="$class"
    Write-Host "✅ $class backfill done"
}

Write-Host "📊 Iniciando workers..."
php artisan queue:work --queue=species-sync --sleep=3 --timeout=120
```

Ejecuta:
```bash
powershell .\run-full-pipeline.ps1
```

---

## 📊 VERIFICACIÓN FINAL

Cuando todo haya terminado:

```bash
# Ver cuántas especies sincronizadas
php -r "
require 'vendor/autoload.php';
\$app = require 'bootstrap/app.php';
\$synced = DB::table('taxa')->where('sync_status', 'synced')->count();
echo \"\\n✅ TOTAL SINCRONIZADAS: \$synced\\n\";
"

# Ver una especie de ejemplo
php artisan tinker
>>> App\Models\Taxa::where('sync_status', 'synced')->first();
# Deberías ver: {scientific_name: "...", sync_status: "synced", ...}

# Exit
exit
```

---

## 💡 RESUMEN PUNTUAL

| Acción | Comando | Resultado Esperado |
|--------|---------|-------------------|
| **Enqueue** | `php artisan species:backfill-catalog --class="Aves"` | `✅ Clase Aves: 1900 especies enqueued` |
| **Procesar** | `php artisan queue:work --queue=species-sync` | `✅ Especie enriquecida exitosamente: Panthera onca` |
| **Ver progreso** | `php -r "...count synced..."` | `Synced: 500` (número crece) |
| **Ver logs** | `tail -f storage/logs/laravel.log` | Sin errores, o solo `Rate limit` normales |
| **Parar** | `CTRL+C` | Proceso termina limpiamente |

---

## 🎯 TODO EN UNA LÍNEA (Quickstart)

```bash
# Terminal 1: Enqueue
php artisan species:backfill-catalog --class="Aves" && php artisan species:backfill-catalog --class="Reptilia"

# Terminal 2: Procesa (mientras lo anterior corre)
php artisan queue:work --queue=species-sync --sleep=3
```

**Listo. Eso es todo.** El pipeline maneja el resto automáticamente.
