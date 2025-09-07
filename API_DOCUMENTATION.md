# API de Biodiversidad

Esta API permite consultar información sobre especies y taxones de biodiversidad, integrando datos de fuentes externas como iNaturalist y almacenándolos localmente para un acceso más rápido y eficiente.

## Autenticación

La mayoría de los endpoints no requieren autenticación, pero algunos pueden estar protegidos. Para los endpoints que requieren autenticación, se utiliza el sistema de autenticación estándar de Laravel Sanctum.

## Endpoints Principales

### Búsqueda de Taxones

Busca taxones por nombre científico o común.

```
GET /api/taxa/search?q=query&rank=species&per_page=10&page=1
```

**Parámetros de consulta:**
- `q` (requerido): Término de búsqueda (nombre científico o común).
- `rank`: Filtro opcional por rango taxonómico (especie, género, familia, etc.).
- `per_page`: Número de resultados por página (por defecto: 15, máximo: 100).
- `page`: Número de página (por defecto: 1).

**Ejemplo de respuesta exitosa (200 OK):**
```json
{
  "success": true,
  "message": "Búsqueda completada correctamente",
  "data": [
    {
      "id": 12345,
      "scientific_name": "Panthera onca",
      "common_name": "Jaguar",
      "rank": "species",
      "rank_level": 10,
      "wikipedia_url": "https://es.wikipedia.org/wiki/Panthera_onca",
      "observations_count": 1250,
      "default_photo": {
        "url": "https://inaturalist-open-data.s3.amazonaws.com/...",
        "attribution": "(c) John Doe, some rights reserved (CC BY-NC)"
      }
    }
  ],
  "meta": {
    "source": "local",
    "cached": false,
    "pagination": {
      "total": 1,
      "per_page": 15,
      "current_page": 1,
      "last_page": 1
    }
  }
}
```

### Obtener un Taxón por ID

Obtiene información detallada de un taxón específico.

```
GET /api/taxa/{id}
```

**Parámetros de URL:**
- `id` (requerido): ID del taxón.

**Parámetros de consulta:**
- `refresh`: Si es `true`, fuerza la actualización desde la API externa (por defecto: `false`).

**Ejemplo de respuesta exitosa (200 OK):**
```json
{
  "success": true,
  "message": "Taxón obtenido correctamente",
  "data": {
    "id": 12345,
    "scientific_name": "Panthera onca",
    "common_name": "Jaguar",
    "rank": "species",
    "rank_level": 10,
    "extinct": false,
    "threatened": true,
    "wikipedia_url": "https://es.wikipedia.org/wiki/Panthera_onca",
    "wikipedia_summary": "El jaguar (Panthera onca) es un felino...",
    "observations_count": 1250,
    "default_photo": {
      "url": "https://inaturalist-open-data.s3.amazonaws.com/...",
      "attribution": "(c) John Doe, some rights reserved (CC BY-NC)"
    },
    "conservation_status": {
      "status": "NT",
      "status_name": "Casi Amenazada",
      "iucn": 3.1,
      "authority": "IUCN"
    },
    "created_at": "2023-01-15T10:30:00Z",
    "updated_at": "2023-06-20T15:45:00Z"
  },
  "meta": {
    "source": "api",
    "cached": false
  }
}
```

### Obtener Observaciones de un Taxón

Obtiene las observaciones registradas para un taxón específico.

```
GET /api/taxa/{taxon}/observations?per_page=10&page=1&quality_grade=research
```

**Parámetros de URL:**
- `taxon` (requerido): ID del taxón.

**Parámetros de consulta:**
- `per_page`: Número de resultados por página (por defecto: 15, máximo: 100).
- `page`: Número de página (por defecto: 1).
- `quality_grade`: Filtro opcional por calidad de la observación (research, needs_id, casual).
- `order_by`: Campo para ordenar los resultados (created_at, observed_on, votes).
- `order`: Dirección del orden (asc, desc).

**Ejemplo de respuesta exitosa (200 OK):**
```json
{
  "success": true,
  "message": "Observaciones obtenidas correctamente",
  "data": [
    {
      "id": 987654,
      "observed_on": "2023-05-15",
      "time_observed_at": "2023-05-15T08:30:00-05:00",
      "quality_grade": "research",
      "license": "CC-BY-NC",
      "url": "https://www.inaturalist.org/observations/987654",
      "location": {
        "latitude": 19.4326,
        "longitude": -99.1332
      },
      "place_guess": "Ciudad de México, México",
      "photos": [
        {
          "url": "https://inaturalist-open-data.s3.amazonaws.com/...",
          "license_code": "CC-BY-NC",
          "attribution": "(c) Jane Smith, some rights reserved (CC BY-NC)"
        }
      ]
    }
  ],
  "meta": {
    "source": "api",
    "cached": true,
    "pagination": {
      "total": 1,
      "per_page": 15,
      "current_page": 1,
      "last_page": 1
    }
  }
}
```

### Sincronizar Observaciones de un Taxón

Sincroniza las observaciones de un taxón desde la API de iNaturalist y las almacena localmente.

```
POST /api/taxa/{taxon}/sync-observations
```

**Parámetros de URL:**
- `taxon` (requerido): ID del taxón.

**Ejemplo de respuesta exitosa (200 OK):**
```json
{
  "success": true,
  "message": "Se sincronizaron 25 de 25 observaciones para el taxón Panthera onca",
  "data": {
    "taxon": {
      "id": 12345,
      "scientific_name": "Panthera onca",
      "common_name": "Jaguar"
    },
    "observations": {
      "total_processed": 25,
      "stored_count": 25,
      "error_count": 0,
      "errors": []
    }
  },
  "meta": {
    "source": "api",
    "cached": false
  }
}
```

## Manejo de Errores

La API devuelve códigos de estado HTTP estándar para indicar el resultado de las operaciones:

- `200 OK`: La solicitud se completó con éxito.
- `400 Bad Request`: La solicitud es inválida (por ejemplo, parámetros faltantes o incorrectos).
- `401 Unauthorized`: Se requiere autenticación pero no se proporcionó o es inválida.
- `403 Forbidden`: El usuario autenticado no tiene permiso para realizar la acción solicitada.
- `404 Not Found`: El recurso solicitado no existe.
- `422 Unprocessable Entity`: La solicitud es válida pero no se pudo procesar (por ejemplo, validación fallida).
- `500 Internal Server Error`: Ocurrió un error en el servidor.

Las respuestas de error siguen un formato estándar:

```json
{
  "success": false,
  "message": "Descripción del error",
  "errors": {
    "campo": ["Mensaje de error específico del campo"]
  }
}
```

## Límites de Tasa

La API tiene límites de tasa para evitar abusos. Los límites predeterminados son:

- 60 solicitudes por minuto por IP para endpoints públicos.
- 120 solicitudes por minuto por usuario autenticado.

Si se exceden estos límites, la API devolverá un código de estado `429 Too Many Requests`.

## Caché

La API utiliza un sistema de caché para mejorar el rendimiento. Las respuestas pueden incluir un encabezado `X-Cache: HIT` o `X-Cache: MISS` para indicar si la respuesta provino de la caché.

Para forzar una actualización de los datos, use el parámetro `refresh=true` en los endpoints que lo soporten.

## Soporte

Para soporte técnico o preguntas, contacte al equipo de desarrollo en [correo@ejemplo.com](mailto:correo@ejemplo.com).
