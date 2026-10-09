# ⚡ CHEAT SHEET - Comandos Rápidos

## 🚀 EJECUCIÓN COMPLETA EN 3 LÍNEAS

```bash
# Terminal 1
php artisan species:backfill-catalog --class="Aves"

# Terminal 2
php artisan queue:work --queue=species-sync --sleep=3

# Terminal 3 (monitorear cada 5 min)
php -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; echo DB::table('taxa')->where('sync_status','synced')->count().' synced'.PHP_EOL;"
```

---

## 📊 COMANDOS DE MONITOREO

```bash
# Ver cuántas especies sincronizadas
php -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$s=DB::table('taxa')->where('sync_status','synced')->count(); \$p=DB::table('taxa')->where('sync_status','pending')->count(); echo \"Synced: \$s | Pending: \$p\n\";"

# Ver jobs en cola
php -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; echo DB::table('jobs')->count().' jobs en cola'.PHP_EOL;"

# Ver failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Ver logs en tiempo real (errores)
tail -f storage/logs/laravel.log | grep -i "error\|failed"

# Ver logs en tiempo real (todo)
tail -f storage/logs/laravel.log

# Ver número de jobs específico
php artisan tinker
>>> DB::table('jobs')->count();
>>> exit
```

---

## 🔥 COMANDOS DE BACKFILL

```bash
# Una clase
php artisan species:backfill-catalog --class="Aves"

# Todas las clases (secuencial)
for class in Aves Reptilia Mammalia Amphibia Insecta Plantae Fungi; do
  echo "Backfilling $class..."
  php artisan species:backfill-catalog --class="$class"
done

# Ver qué clases hay disponibles
# Están en el comando: Aves, Reptilia, Amphibia, Mammalia, Insecta, Plantae, Fungi, Arachnida, Malacostraca, Actinopterygii, etc.
```

---

## 🛠️ TROUBLESHOOTING RÁPIDO

```bash
# ¿Backfill stuck? Reinicia
CTRL+C
php artisan species:backfill-catalog --class="Aves" --page=XX  # Retomar en página XX

# ¿Jobs no se procesan?
# 1. Ver si worker está corriendo
ps aux | grep "queue:work"

# 2. Reiniciar worker
CTRL+C
php artisan queue:work --queue=species-sync

# ¿Muchos failed jobs?
php artisan queue:failed  # Ver cuáles
php artisan queue:retry all  # Reintentar todos

# ¿Rate limit?
# Es normal. El worker reintenta automáticamente. Ver logs:
tail -f storage/logs/laravel.log | grep "Rate limit"
```

---

## 📈 ESCALAMIENTO (4 Workers)

```bash
# Terminal A
php artisan queue:work --queue=species-sync --sleep=3

# Terminal B
php artisan queue:work --queue=species-sync --sleep=3

# Terminal C
php artisan queue:work --queue=species-sync --sleep=3

# Terminal D
php artisan queue:work --queue=species-sync --sleep=3

# Resultado: ~4x más rápido
```

---

## 🧪 VERIFICACIÓN

```bash
# ¿Funcionan los servicios?
php artisan tinker

>>> $gbif = app('App\Services\Api\GbifService');
>>> $result = $gbif->searchTaxon('Panthera onca');
>>> echo $result['success'] ? 'GBIF OK' : 'GBIF ERROR';

>>> $inat = app('App\Services\Api\INaturalistService');
>>> $result = $inat->searchTaxon('Panthera onca');
>>> echo $result['success'] ? 'iNat OK' : 'iNat ERROR';

>>> exit

# ¿Taxa en BD?
php artisan tinker
>>> App\Models\Taxa::count();
>>> App\Models\Taxa::where('sync_status','synced')->count();
>>> App\Models\Taxa::first();
>>> exit
```

---

## 🗑️ LIMPIAR / RESET

```bash
# Limpiar failed jobs
php artisan queue:flush

# Limpiar TODO (peligroso!)
php artisan queue:clear

# Reset taxa a pending (restart)
php artisan tinker
>>> DB::table('taxa')->update(['sync_status' => 'pending', 'sync_attempts' => 0]);
>>> exit
```

---

## 📋 ESTADO ACTUAL

```bash
# Quick check de todo
php -r "
require 'vendor/autoload.php';
\$a=require 'bootstrap/app.php';
\$t=DB::table('taxa')->count();
\$s=DB::table('taxa')->where('sync_status','synced')->count();
\$p=DB::table('taxa')->where('sync_status','pending')->count();
\$f=DB::table('taxa')->where('sync_status','failed')->count();
\$j=DB::table('jobs')->count();
echo \"Taxa: \$t | Synced: \$s | Pending: \$p | Failed: \$f | Jobs en cola: \$j\n\";
"
```

---

## 🎯 FLUJO TÍPICO

```
1. php artisan species:backfill-catalog --class="Aves"
   ↓ Enqueue 1900 jobs
   
2. php artisan queue:work --queue=species-sync
   ↓ Procesa jobs (8-10 horas)
   
3. Monitorear con comando de estadísticas
   ↓ Synced debería crecer de 0 → 1900
   
4. Cuando termina:
   ↓ Synced: 1900 ✅
   ↓ Pending: 0 ✅
   ↓ Failed: <10 (normal)
```

---

## 📱 COMANDOS UNI-LÍNEA

```bash
# Backfill TODO y procesar (para producción con supervisor)
php artisan species:backfill-catalog --class="Insecta" & php artisan queue:work --queue=species-sync

# Ver progreso cada segundo
watch -n 1 'cd /ruta && php -r "require \"vendor/autoload.php\"; \$a=require \"bootstrap/app.php\"; echo DB::table(\"taxa\")->where(\"sync_status\",\"synced\")->count();"'

# Mata todos los php.exe (Windows)
taskkill /IM php.exe /F

# Mata todos los php (Linux/Mac)
pkill -f "php artisan queue:work"
```

---

## 💾 INFORMACIÓN CRITICAL

| Dato | Valor |
|------|-------|
| **Tabla principal** | `taxa` |
| **Cola** | `species-sync` |
| **Jobs** | `EnrichSpeciesJob`, `SyncRegionOccurrencesJob` |
| **Workers** | 1-8 (recomendado 4) |
| **Rate limit** | 50 req/min por worker |
| **Timeout job** | 120 segundos |
| **Reintentos** | 3 con backoff: 30s, 120s, 600s |
| **Duración total** | 3-4 días (170k especies) |

---

## 🎓 EXPLICACIÓN EN 1 MINUTO

```
¿Qué hace?
Sincroniza 170k especies de Colombia desde GBIF + iNaturalist

¿Cómo?
1. Backfill enqueue jobs (1-2 min)
2. Workers procesan jobs en background (3-4 días)
3. Cada job: GBIF + iNat + Merge = 1 especie enriquecida
4. Usuario siempre lee de BD, nunca espera APIs

¿Por qué dos terminales?
1. Una para enqueue (backfill rápido)
2. Otra para procesar (background, puede tardar días)

¿Cuánto tarda?
Enqueue: 2-3 min
Procesar: 8-10 horas (1 clase) ó 3-4 días (todas)
```

---

**Leer primero:** `FINAL_STATUS.md` o `QUICK_START_EXECUTION.md`
