# EcoIses · API y panel de administración

Backend de **EcoIses**, la plataforma de educación y divulgación de la biodiversidad colombiana.
Expone una API REST (Laravel 12 + Sanctum) que consume el frontend React (`Ecoises-app`) y un panel de
administración en `/admin` hecho con Filament.

## Puesta en marcha (Laragon)

```bash
composer install
cp .env.example .env        # ajusta DB_* si hace falta
php artisan key:generate
php artisan migrate
php artisan storage:link
```

Laragon sirve el proyecto en `http://biodiversidad-api.test` (API en `/api`, panel en `/admin`).

### El worker de colas (obligatorio)

El enriquecimiento de especies (fotos, taxonomía y ecología de GBIF, iNaturalist y EOL) se hace en
segundo plano en la cola `species-sync`. Sin un worker escuchando **las dos colas**, las especies nuevas se
quedan sin foto y las tareas se acumulan:

```bash
composer queue
# equivale a: php artisan queue:work --queue=default,species-sync --tries=3 --timeout=120
```

En Laragon puedes dejarlo siempre encendido con *Menú › Procfile* añadiendo:

```
ecoises-queue: php C:/laragon/www/biodiversidad-api/artisan queue:work --queue=default,species-sync --tries=3
```

### Velocidad en local

| Qué | Cómo | Efecto medido |
|---|---|---|
| Cachés de Laravel y Filament | `composer speed` (y `composer speed:clear` para deshacer) | ~3,3 s → ~0,85 s por petición |
| OPcache de PHP | Laragon › PHP › php.ini: `opcache.enable=1` | el mayor salto; recomendado |
| Logs | `LOG_LEVEL=warning` en `.env` | menos escritura en disco |
| Caché | `CACHE_STORE=file` en `.env` | menos consultas a MySQL |

Si cambias rutas o vistas después de `composer speed`, vuelve a ejecutarlo. No se cachea la configuración
para que los tests sigan usando SQLite en memoria.

## Pruebas

```bash
composer test
```

Los tests usan SQLite en memoria y construyen solo las tablas que necesitan (no ejecutan las migraciones,
que tienen sentencias exclusivas de MySQL).

## Cómo está organizado

```
app/
├── Console/Commands/        Tareas de mantenimiento (backfill del catálogo, recálculo de conservación…)
├── Filament/                Panel /admin: recursos, páginas y widgets
├── Http/Controllers/
│   ├── Api/                 Un controlador por recurso de la API
│   └── Auth/                Registro, login, Google, recuperación de contraseña
├── Jobs/                    Trabajos en cola (enriquecimiento de especies, audio)
├── Models/                  Modelos Eloquent
├── Policies/                Permisos del panel (Filament Shield)
└── Services/                Lógica de negocio, agrupada por dominio:
    ├── Biodiversity/        Catálogo de especies, explorador geográfico, estatus y conservación
    ├── ExternalApi/         Clientes HTTP de GBIF, iNaturalist y EOL (con caché)
    ├── Learning/            Progreso, evaluación de actividades y certificados
    ├── Editorial/           Borradores, versiones y publicación de contenidos y anuncios
    ├── Gamification/        Puntos, niveles, logros y retos de campo
    ├── Moderation/          Notificaciones de reportes
    └── Audio/               Narración con ElevenLabs y Gemini
```

Regla general: **los controladores validan y responden; la lógica vive en `Services/`.**

## Módulos

### Biodiversidad

- `GET /api/explorer/nearby` y `/api/explorer/national`: catálogo geográfico. GBIF dice qué especies hay
  en la zona y el catálogo local (`taxa`) aporta nombres, fotos y estatus (`ExplorerService`).
- `GET /api/taxa/search`, `/api/taxa/{id}`, `/related`, `/observations`: fichas del catálogo local
  (`TaxonCatalogService`).
- Las listas **nunca** llaman a APIs externas durante la petición: si una especie está incompleta se encola
  `EnrichSpeciesJob`. Solo la ficha individual consulta iNaturalist en línea cuando no tiene datos suficientes.
- **Importancia ecológica** (`EcologicalImportanceService`): se arma con las interacciones publicadas en
  [GloBI](https://www.globalbioticinteractions.org) (qué come, qué poliniza, de qué plantas dispersa
  semillas, quién la depreda). Es gratis y propia de cada especie. Se descarga en la cola
  (`SyncSpeciesInteractionsJob`) la primera vez que alguien abre la ficha y se renueva cada 6 meses.
  Un rol solo se afirma si hay suficientes registros, y las especies relacionadas que ya están en el
  catálogo aparecen con su nombre común y un enlace a su ficha.
- `TaxonSyncService` es el único lugar donde un payload de iNaturalist se convierte en un registro local.
  Las reglas de procedencia (nombre común, nativa/endémica/introducida y conservación) viven ahí,
  en `EstablishmentStatusResolver` y en `ConservationStatusResolver`.

### Retos de campo (avistamientos)

Los avistamientos ya no son una red social: son la **evidencia de los retos**.

1. En el panel (*Gamificación › Retos de campo*) se crea un reto: especie, avistamientos necesarios,
   puntos e insignia opcional.
2. El usuario lo ve en `GET /api/challenges` y sube su avistamiento con `POST /api/observations`
   enviando `challenge_id`, la especie y la foto.
3. `ChallengeService` valida que el reto esté vigente, que la especie coincida y que haya foto, y aprueba
   la evidencia. Al llegar a la meta otorga los puntos **una sola vez** y la insignia.
4. En *Gamificación › Evidencias de retos* un moderador puede rechazar una evidencia; si el usuario baja de
   la meta, los puntos se revierten.

Un avistamiento sin reto se guarda, pero no da puntos.

### Aprendizaje

Contenidos (`educational_content`) de tipo curso, artículo o recurso, con flujo editorial
`draft → pending → reviewed → published`, versiones, lecciones, actividades evaluadas en el servidor,
progreso, prerrequisitos y certificados verificables.

### Gamificación

`GamificationService` otorga y revierte puntos de forma idempotente (`point_transactions`) y sube de nivel
(`levels`). `AchievementService` evalúa logros por tipo de requisito: actividades, lecciones, cursos,
artículos, puntos, retos completados y especies observadas.

## Permisos del panel

Los recursos usan Filament Shield. `super_admin` ve todo. Para dar acceso a editores o moderadores a los
recursos nuevos (Retos, Evidencias, Niveles), genera los permisos y asígnalos en *Roles*:

```bash
php artisan shield:generate --resource=ChallengeResource,ChallengeEvidenceResource,LevelResource --ignore-existing-policies --panel=admin
```

## Documentación anterior

Las notas de fases previas del proyecto están en [`docs/historial/`](docs/historial/).
