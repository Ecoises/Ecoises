-- Base de datos para aplicación de biodiversidad universitaria
-- Optimizada para integración con APIs y gamificación

-- ================================================
-- USUARIOS Y AUTENTICACIÓN
-- ================================================

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    profile_picture_url TEXT,
    academic_level ENUM('estudiante', 'profesor', 'investigador', 'visitante') DEFAULT 'estudiante',
    faculty VARCHAR(100),
    career VARCHAR(100),
    bio TEXT,
    total_score INT DEFAULT 0,
    level INT DEFAULT 1,
    experience_points INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    email_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_activity_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ================================================
-- TAXONOMÍA Y ESPECIES (OPTIMIZADA PARA MÚLTIPLES APIs)
-- ================================================

-- Tabla principal de especies (solo datos esenciales locales)
CREATE TABLE taxa (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Información taxonómica básica (siempre presente)
    scientific_name VARCHAR(255) NOT NULL UNIQUE,
    common_name VARCHAR(255),
    kingdom VARCHAR(100),
    phylum VARCHAR(100),
    class VARCHAR(100),
    order_name VARCHAR(100),
    family VARCHAR(100),
    genus VARCHAR(100),
    species VARCHAR(100),
    
    -- Metadatos locales
    conservation_status ENUM('LC', 'NT', 'VU', 'EN', 'CR', 'EW', 'EX', 'DD', 'NE'),
    is_native BOOLEAN,
    is_endemic BOOLEAN,
    
    -- Estadísticas de uso local
    observation_count INT DEFAULT 0, -- cuántas veces se ha observado en la app
    identification_count INT DEFAULT 0, -- cuántas veces se ha identificado
    last_observed_at TIMESTAMP,
    
    -- Metadatos
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Referencias a APIs externas (tabla independiente)
CREATE TABLE taxon_api_references (
    id INT AUTO_INCREMENT PRIMARY KEY,
    taxon_id INT NOT NULL,
    api_source ENUM('inaturalist', 'gbif', 'eol', 'fishbase', 'birdlife', 'tropicos', 'worms', 'otros') NOT NULL,
    external_id VARCHAR(100) NOT NULL, -- ID en la API externa
    api_url TEXT, -- URL directa para consultas
    confidence_score DECIMAL(3, 2) DEFAULT 1.0, -- qué tan confiable es esta referencia (0.0-1.0)
    is_primary BOOLEAN DEFAULT FALSE, -- fuente primaria preferida para esta especie
    last_verified_at TIMESTAMP, -- última vez que se verificó que existe en la API
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (taxon_id) REFERENCES taxa(id) ON DELETE CASCADE,
    UNIQUE KEY unique_taxon_api (taxon_id, api_source, external_id)
);

-- ================================================
-- CACHE UNIFICADO DE APIs (CONSOLIDADO Y OPTIMIZADO)
-- ================================================

CREATE TABLE unified_api_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Identificación del cache
    cache_key VARCHAR(255) NOT NULL UNIQUE, -- hash único de la consulta
    cache_type ENUM('taxon_data', 'general_query', 'search_results') NOT NULL,
    
    -- Referencias opcionales
    taxon_id INT, -- NULL para cachés no relacionados con taxones específicos
    user_id INT, -- NULL para cachés globales
    
    -- Datos de la API
    api_source ENUM('inaturalist', 'gbif', 'eol', 'fishbase', 'birdlife', 'tropicos', 'worms', 'otros') NOT NULL,
    data_type ENUM('description', 'images', 'sounds', 'distribution', 'conservation', 'characteristics', 'references', 'taxonomy', 'search', 'otros') NOT NULL,
    request_url TEXT,
    request_params JSON, -- parámetros de la consulta original
    
    -- Datos cacheados
    response_data JSON NOT NULL,
    response_metadata JSON, -- headers, timestamps, etc.
    
    -- Control de cache
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    last_accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    hit_count INT DEFAULT 1,
    
    FOREIGN KEY (taxon_id) REFERENCES taxa(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Índices para búsquedas rápidas
    INDEX idx_cache_key (cache_key),
    INDEX idx_cache_lookup (taxon_id, api_source, data_type),
    INDEX idx_cache_expiration (expires_at),
    INDEX idx_cache_access (last_accessed_at)
);

-- Índices GIN para JSON en MySQL/MariaDB
-- MySQL utiliza índices B-tree para JSON, por lo que este índice se adapta.
CREATE INDEX idx_response_data ON unified_api_cache (CAST(response_data AS CHAR(50) ARRAY));

-- Configuraciones de APIs (para manejar límites y configuraciones)
CREATE TABLE api_configurations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_source ENUM('inaturalist', 'gbif', 'eol', 'fishbase', 'birdlife', 'tropicos', 'worms', 'otros') NOT NULL UNIQUE,
    base_url TEXT NOT NULL,
    api_key_required BOOLEAN DEFAULT FALSE,
    
    -- Límites de uso
    rate_limit_requests INT, -- requests por período
    rate_limit_period INT, -- período en segundos
    daily_limit INT, -- límite diario
    monthly_limit INT, -- límite mensual
    
    -- TTL por tipo de datos (en segundos)
    cache_ttl_description INT DEFAULT 604800, -- 1 semana
    cache_ttl_images INT DEFAULT 86400, -- 1 día
    cache_ttl_sounds INT DEFAULT 86400, -- 1 día
    cache_ttl_distribution INT DEFAULT 2592000, -- 1 mes
    cache_ttl_conservation INT DEFAULT 2592000, -- 1 mes
    cache_ttl_characteristics INT DEFAULT 604800, -- 1 semana
    cache_ttl_references INT DEFAULT 2592000, -- 1 mes
    
    -- Estado de la API
    is_active BOOLEAN DEFAULT TRUE,
    last_health_check TIMESTAMP,
    health_status ENUM('healthy', 'degraded', 'unavailable') DEFAULT 'healthy',
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


-- ================================================
-- OBSERVACIONES DE USUARIOS
-- ================================================

CREATE TABLE locations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    radius_km DECIMAL(5, 2) NOT NULL DEFAULT 1.00,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices
CREATE INDEX idx_locations_coordinates ON locations (latitude, longitude);
CREATE INDEX idx_locations_is_active ON locations (is_active);

CREATE TABLE observations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    taxon_id INT, -- NULL si la especie no está identificada aún
    
    -- Ubicación de la observación
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    location_accuracy INT, -- precisión en metros
    location_name VARCHAR(255), -- nombre descriptivo del lugar
    location_description TEXT, -- descripción adicional del hábitat o lugar
    
    -- Detalles de la observación
    observed_at TIMESTAMP NOT NULL,
    description TEXT,
    notes TEXT,
    
    -- Estado de identificación
    identification_status ENUM('sin_identificar', 'sugerida', 'confirmada', 'controvertida') DEFAULT 'sin_identificar',
    confidence_level ENUM('baja', 'media', 'alta'),
    
    -- Datos ambientales
    weather_conditions VARCHAR(100),
    temperature DECIMAL(4, 2),
    humidity INT,
    
    -- Gamificación y calidad
    quality_score DECIMAL(3, 2) DEFAULT 0.0, -- 0.0 a 5.0
    is_featured BOOLEAN DEFAULT FALSE,
    
    -- Metadatos
    is_public BOOLEAN DEFAULT TRUE,
    is_research_grade BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (taxon_id) REFERENCES taxa(id) ON DELETE SET NULL,
    FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL
);

-- Tabla separada para múltiples fotos por observación
CREATE TABLE observation_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    observation_id INT NOT NULL,
    photo_url TEXT NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    caption TEXT,
    photo_order INT DEFAULT 1, -- orden de las fotos
    file_size INT, -- tamaño en bytes
    image_width INT,
    image_height INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (observation_id) REFERENCES observations(id) ON DELETE CASCADE
);

-- Constraint para asegurar que solo hay una foto primaria por observación
-- MySQL utiliza WHERE para UNIQUE KEY
CREATE UNIQUE INDEX idx_one_primary_photo_per_observation
ON observation_photos (observation_id, is_primary);

-- ================================================
-- IDENTIFICACIONES Y SUGERENCIAS
-- ================================================

CREATE TABLE identifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    observation_id INT NOT NULL,
    user_id INT NOT NULL,
    taxon_id INT NOT NULL,
    confidence ENUM('baja', 'media', 'alta') DEFAULT 'media',
    reasoning TEXT,
    is_automatic BOOLEAN DEFAULT FALSE, -- identificación por IA
    ai_confidence DECIMAL(5, 4), -- confianza de la IA (0.0-1.0)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (observation_id) REFERENCES observations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (taxon_id) REFERENCES taxa(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_user_observation (user_id, observation_id)
);

-- ================================================
-- SISTEMA DE GAMIFICACIÓN
-- ================================================

CREATE TABLE achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon_url TEXT,
    category ENUM('observador', 'identificador', 'explorador', 'social', 'especial') NOT NULL,
    points INT DEFAULT 0,
    requirement_type ENUM('count', 'streak', 'diversity', 'quality', 'collaboration') NOT NULL,
    requirement_value INT NOT NULL,
    requirement_criteria JSON, -- criterios específicos flexibles
    is_active BOOLEAN DEFAULT TRUE,
    rarity ENUM('comun', 'raro', 'epico', 'legendario') DEFAULT 'comun'
);

CREATE TABLE user_achievements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    achievement_id INT NOT NULL,
    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    progress_data JSON, -- datos de progreso hacia el logro
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_user_achievement (user_id, achievement_id)
);

CREATE TABLE point_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    points INT NOT NULL, -- puede ser positivo o negativo
    transaction_type ENUM('observacion', 'identificacion', 'logro', 'bonus', 'penalizacion') NOT NULL,
    reference_id INT, -- ID de la observación, identificación, logro, etc.
    reference_type VARCHAR(50),
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ================================================
-- SISTEMA EDUCATIVO INTERACTIVO
-- ================================================

-- Cursos principales (colecciones de lecciones)
CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    thumbnail_url TEXT,
    difficulty_level ENUM('principiante', 'intermedio', 'avanzado') DEFAULT 'principiante',
    category ENUM('taxonomia', 'ecologia', 'conservacion', 'identificacion', 'botanica', 'zoologia', 'general') NOT NULL,
    estimated_duration INT, -- minutos totales
    
    -- Gamificación
    completion_points INT DEFAULT 100,
    achievement_id INT, -- logro especial al completar
    
    -- Taxonomía relacionada
    related_taxa JSON, -- especies que se cubren en el curso
    target_location_ids JSON, -- ubicaciones del campus relacionadas
    
    -- Metadatos
    author_id INT,
    is_published BOOLEAN DEFAULT FALSE,
    enrollment_count INT DEFAULT 0,
    completion_rate DECIMAL(5, 2) DEFAULT 0.0, -- porcentaje promedio de finalización
    rating_average DECIMAL(3, 2) DEFAULT 0.0, -- calificación promedio 1-5
    rating_count INT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE SET NULL
);

-- Lecciones dentro de cada curso
CREATE TABLE lessons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    lesson_order INT NOT NULL, -- orden dentro del curso
    lesson_type ENUM('teoria', 'interactiva', 'practica', 'evaluacion') DEFAULT 'teoria',
    
    -- Contenido teórico
    content_text TEXT,
    media_url TEXT,
    media_type ENUM('video', 'audio', 'imagen', 'animacion', 'modelo_3d'),
    
    -- Configuración
    estimated_duration INT, -- minutos
    is_mandatory BOOLEAN DEFAULT TRUE, -- debe completarse para avanzar
    unlock_requirements JSON, -- requisitos para desbloquear (lecciones previas, puntos, etc.)
    
    -- Metadatos
    is_published BOOLEAN DEFAULT FALSE,
    view_count INT DEFAULT 0,
    completion_rate DECIMAL(5, 2) DEFAULT 0.0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

-- Actividades interactivas dentro de las lecciones
CREATE TABLE lesson_activities (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lesson_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    activity_order INT NOT NULL, -- orden dentro de la lección
    activity_type ENUM(
        'quiz_multiple', 'quiz_true_false', 'drag_drop', 'matching',
        'fill_blanks', 'image_hotspot', 'classification', 'sequencing',
        'memory_game', 'word_search', 'crossword'
    ) NOT NULL,
    
    -- Contenido de la actividad
    instructions TEXT,
    content_data JSON NOT NULL, -- estructura específica según el tipo de actividad
    correct_answers JSON, -- respuestas correctas
    hints JSON, -- pistas opcionales
    
    -- Configuración de puntuación
    max_points INT DEFAULT 10,
    passing_score INT DEFAULT 7, -- puntos mínimos para aprobar
    time_limit INT, -- segundos (NULL = sin límite)
    attempts_allowed INT DEFAULT 3, -- intentos permitidos
    
    -- Feedback
    success_message TEXT,
    failure_message TEXT,
    explanation TEXT, -- explicación de las respuestas
    
    is_mandatory BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
);

-- Añadir constraints de validación JSON (esto no es compatible con MySQL, se puede omitir o manejar a nivel de aplicación)
-- ALTER TABLE lesson_activities
-- ADD CONSTRAINT check_content_data_valid
-- CHECK (content_data IS NOT NULL AND json_typeof(content_data) = 'object');

-- ALTER TABLE lesson_activities
-- ADD CONSTRAINT check_correct_answers_valid
-- CHECK (correct_answers IS NOT NULL AND json_typeof(correct_answers) != 'null');


-- Progreso del usuario en cursos
CREATE TABLE user_course_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    course_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    last_accessed_at TIMESTAMP NULL,
    
    -- Progreso
    current_lesson_id INT,
    lessons_completed INT DEFAULT 0,
    total_lessons INT DEFAULT 0,
    progress_percentage DECIMAL(5, 2) DEFAULT 0.0,
    
    -- Puntuación
    total_points_earned INT DEFAULT 0,
    total_points_possible INT DEFAULT 0,
    final_score DECIMAL(5, 2),
    
    -- Tiempo dedicado
    total_time_spent INT DEFAULT 0, -- segundos
    
    -- Calificación del curso
    user_rating INT, -- 1-5 estrellas
    user_feedback TEXT,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (current_lesson_id) REFERENCES lessons(id) ON DELETE SET NULL,
    
    UNIQUE KEY unique_enrollment (user_id, course_id)
);

-- Progreso detallado en lecciones
CREATE TABLE user_lesson_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    lesson_id INT NOT NULL,
    enrollment_id INT NOT NULL,
    
    -- Estado
    status ENUM('no_iniciada', 'en_progreso', 'completada', 'bloqueada') DEFAULT 'no_iniciada',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    last_accessed_at TIMESTAMP NULL,
    
    -- Progreso en actividades
    activities_completed INT DEFAULT 0,
    total_activities INT DEFAULT 0,
    
    -- Puntuación
    points_earned INT DEFAULT 0,
    points_possible INT DEFAULT 0,
    
    -- Tiempo
    time_spent INT DEFAULT 0, -- segundos
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    FOREIGN KEY (enrollment_id) REFERENCES user_course_enrollments(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_lesson_progress (user_id, lesson_id)
);

-- Respuestas y intentos en actividades
CREATE TABLE user_activity_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    activity_id INT NOT NULL,
    lesson_progress_id INT NOT NULL,
    
    -- Intento
    attempt_number INT NOT NULL, -- 1, 2, 3...
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    
    -- Respuestas del usuario
    user_answers JSON, -- estructura varía según tipo de actividad
    is_correct BOOLEAN,
    points_earned INT DEFAULT 0,
    time_taken INT, -- segundos
    
    -- Ayudas utilizadas
    hints_used JSON, -- qué pistas usó
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (activity_id) REFERENCES lesson_activities(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_progress_id) REFERENCES user_lesson_progress(id) ON DELETE CASCADE
);

-- Contenido adicional (artículos, videos independientes, etc.)
CREATE TABLE educational_resources (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    resource_type ENUM('articulo', 'video', 'infografia', 'podcast', 'documento', 'galeria') NOT NULL,
    content_text TEXT,
    media_url TEXT,
    thumbnail_url TEXT,
    
    -- Clasificación
    difficulty_level ENUM('principiante', 'intermedio', 'avanzado') DEFAULT 'principiante',
    category ENUM('taxonomia', 'ecologia', 'conservacion', 'identificacion', 'botanica', 'zoologia', 'general') NOT NULL,
    tags JSON, -- etiquetas para búsqueda
    
    -- Duración estimada
    estimated_duration INT, -- minutos
    
    -- Relaciones
    related_taxa JSON, -- especies relacionadas
    related_courses JSON, -- cursos relacionados
    prerequisite_courses JSON, -- cursos requeridos antes de acceder
    
    -- Metadatos
    author_id INT,
    is_published BOOLEAN DEFAULT FALSE,
    is_featured BOOLEAN DEFAULT FALSE,
    view_count INT DEFAULT 0,
    like_count INT DEFAULT 0,
    download_count INT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Seguimiento de recursos educativos independientes
CREATE TABLE user_resource_interactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    resource_id INT NOT NULL,
    
    -- Actividad
    first_viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    view_count INT DEFAULT 1,
    time_spent INT DEFAULT 0, -- segundos totales
    
    -- Estado
    is_completed BOOLEAN DEFAULT FALSE,
    completed_at TIMESTAMP NULL,
    is_bookmarked BOOLEAN DEFAULT FALSE,
    is_liked BOOLEAN DEFAULT FALSE,
    is_downloaded BOOLEAN DEFAULT FALSE,
    
    -- Progreso (para contenido largo)
    progress_percentage DECIMAL(5, 2) DEFAULT 0.0,
    last_position VARCHAR(50), -- posición en video, página en documento, etc.
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (resource_id) REFERENCES educational_resources(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_user_resource (user_id, resource_id)
);

-- ================================================
-- SISTEMA SOCIAL Y COLABORATIVO
-- ================================================

CREATE TABLE comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    observation_id INT NOT NULL,
    parent_comment_id INT, -- para respuestas
    content TEXT NOT NULL,
    is_helpful BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (observation_id) REFERENCES observations(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES comments(id) ON DELETE CASCADE
);

CREATE TABLE user_follows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    follower_id INT NOT NULL,
    following_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (following_id) REFERENCES users(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_follow (follower_id, following_id)
);

CREATE TABLE observation_likes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    observation_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (observation_id) REFERENCES observations(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_like (user_id, observation_id)
);

-- ================================================
-- CHALLENGES Y EVENTOS
-- ================================================

CREATE TABLE challenges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    challenge_type ENUM('conteo', 'diversidad', 'ubicacion', 'temporal', 'colaborativo') NOT NULL,
    start_date TIMESTAMP NOT NULL,
    end_date TIMESTAMP NOT NULL,
    target_value INT, -- meta numérica del desafío
    reward_points INT DEFAULT 0,
    reward_badge_id INT, -- achievement especial
    criteria JSON, -- criterios específicos del desafío
    is_active BOOLEAN DEFAULT TRUE,
    participant_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE challenge_participations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    challenge_id INT NOT NULL,
    progress_value INT DEFAULT 0,
    completed BOOLEAN DEFAULT FALSE,
    completed_at TIMESTAMP NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE,
    
    UNIQUE KEY unique_participation (user_id, challenge_id)
);

-- ================================================
-- CACHE DE APIS EXTERNAS (REMOVIDO - CONSOLIDADO EN unified_api_cache)
-- ================================================

CREATE TABLE api_usage_stats (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_source ENUM('inaturalist', 'gbif', 'eol', 'otros') NOT NULL,
    endpoint VARCHAR(255),
    request_count INT DEFAULT 1,
    date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_api_usage (api_source, endpoint, date)
);

-- ================================================
-- ÍNDICES PARA OPTIMIZACIÓN
-- ================================================

-- Observaciones y fotos
CREATE INDEX idx_observations_user_id ON observations (user_id);
CREATE INDEX idx_observations_taxon_id ON observations (taxon_id);
CREATE INDEX idx_observations_location_id ON observations (location_id);
CREATE INDEX idx_observations_coordinates ON observations (latitude, longitude);
CREATE INDEX idx_observations_is_public ON observations (is_public);
CREATE INDEX idx_observations_observed_at ON observations (observed_at);
CREATE INDEX idx_observations_identification_status ON observations (identification_status);
CREATE INDEX idx_observations_is_research_grade ON observations (is_research_grade);

-- API Cache unificado
CREATE INDEX idx_unified_cache_key ON unified_api_cache(cache_key);
CREATE INDEX idx_unified_cache_lookup ON unified_api_cache(taxon_id, api_source, data_type);
CREATE INDEX idx_unified_cache_expiration ON unified_api_cache(expires_at);
CREATE INDEX idx_unified_cache_access ON unified_api_cache(last_accessed_at);

-- Taxa (especies)
CREATE INDEX idx_taxa_scientific_name ON taxa(scientific_name);
CREATE INDEX idx_taxa_common_name ON taxa(common_name);
CREATE INDEX idx_taxa_observation_count ON taxa(observation_count DESC);

-- API References y Cache
CREATE INDEX idx_api_references_taxon ON taxon_api_references(taxon_id);
CREATE INDEX idx_api_references_source ON taxon_api_references(api_source, external_id);

-- Usuarios y gamificación
CREATE INDEX idx_users_score ON users(total_score DESC);
CREATE INDEX idx_users_level ON users(level DESC);
CREATE INDEX idx_point_transactions_user ON point_transactions(user_id, created_at);

-- Identificaciones
CREATE INDEX idx_identifications_observation ON identifications(observation_id);
CREATE INDEX idx_identifications_user ON identifications(user_id);

-- ================================================
-- TRIGGERS Y FUNCIONES
-- ================================================

-- Actualizar puntuación total del usuario
DELIMITER //
CREATE TRIGGER update_user_score
AFTER INSERT ON point_transactions
FOR EACH ROW
BEGIN
    UPDATE users
    SET total_score = total_score + NEW.points,
        experience_points = experience_points + GREATEST(NEW.points, 0)
    WHERE id = NEW.user_id;
END//
DELIMITER ;

-- Actualizar timestamps
DELIMITER //
CREATE TRIGGER update_observations_timestamp
BEFORE UPDATE ON observations
FOR EACH ROW
BEGIN
    SET NEW.updated_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;

-- ================================================
-- DATOS INICIALES
-- ================================================

-- Logros básicos del sistema
INSERT INTO achievements (name, description, category, points, requirement_type, requirement_value, rarity) VALUES
('Primera Observación', 'Registra tu primera observación de biodiversidad', 'observador', 10, 'count', 1, 'comun'),
('Explorador', 'Registra 10 observaciones', 'observador', 50, 'count', 10, 'comun'),
('Naturalista', 'Registra 50 observaciones', 'observador', 200, 'count', 50, 'raro'),
('Detective de la Naturaleza', 'Ayuda a identificar 5 especies', 'identificador', 25, 'count', 5, 'comun'),
('Experto Taxónomo', 'Ayuda a identificar 25 especies', 'identificador', 150, 'count', 25, 'raro'),
('Diversidad Campus', 'Observa 20 especies diferentes', 'explorador', 100, 'diversity', 20, 'raro'),
('Fotógrafo de la Naturaleza', 'Recibe 50 likes en tus observaciones', 'social', 75, 'count', 50, 'raro'),
('Madrugador', 'Registra observaciones antes de las 7 AM', 'especial', 30, 'count', 1, 'epico'),
('Guardián Nocturno', 'Registra observaciones después de las 9 PM', 'especial', 30, 'count', 1, 'epico'),
-- Logros educativos
('Primer Estudiante', 'Completa tu primer curso', 'social', 50, 'count', 1, 'comun'),
('Aprendiz Dedicado', 'Completa 3 cursos', 'social', 150, 'count', 3, 'raro'),
('Maestro del Campus', 'Completa 10 cursos', 'social', 500, 'count', 10, 'epico'),
('Perfeccionista', 'Completa un curso con 100% de puntuación', 'especial', 100, 'quality', 100, 'raro'),
('Velocista del Saber', 'Completa una lección en menos de 5 minutos', 'especial', 25, 'count', 1, 'epico'),
('Coleccionista de Conocimiento', 'Interactúa con 25 recursos educativos', 'social', 75, 'count', 25, 'comun');

-- Cursos de ejemplo
INSERT INTO courses (title, description, category, difficulty_level, estimated_duration, completion_points, is_published) VALUES
('Introducción a la Biodiversidad del Campus', 'Descubre la increíble variedad de vida que habita en nuestro campus universitario', 'general', 'principiante', 45, 100, TRUE),
('Identificación de Aves Urbanas', 'Aprende a reconocer las aves más comunes en entornos urbanos y campus', 'zoologia', 'intermedio', 60, 150, TRUE),
('Plantas Nativas de Colombia', 'Conoce la flora autóctona y su importancia en la conservación', 'botanica', 'intermedio', 90, 200, TRUE),
('Ecosistemas Acuáticos del Campus', 'Explora la vida en lagos, charcas y fuentes del campus', 'ecologia', 'avanzado', 75, 180, TRUE),
('Conservación y Sostenibilidad', 'Comprende tu papel en la conservación de la biodiversidad', 'conservacion', 'principiante', 40, 120, TRUE);

-- Configuraciones iniciales de APIs
INSERT INTO api_configurations (api_source, base_url, rate_limit_requests, rate_limit_period, daily_limit) VALUES
('inaturalist', 'https://api.inaturalist.org/v1/', 100, 60, 10000), -- 100 req/min, 10k/día
('gbif', 'https://api.gbif.org/v1/', 1000, 60, 100000), -- 1000 req/min, 100k/día
('eol', 'https://eol.org/api/', 120, 60, 5000), -- 120 req/min, 5k/día
('fishbase', 'https://fishbase.ropensci.org/', 60, 60, 2000), -- 60 req/min, 2k/día
('birdlife', 'http://datazone.birdlife.org/species/api/', 30, 60, 1000); -- 30 req/min, 1k/día