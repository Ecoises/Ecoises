-- ============================================================================
-- ARQUITECTURA OPTIMIZADA V2 - SISTEMA EDUCATIVO DE BIODIVERSIDAD
-- ============================================================================
-- Clarificación:
-- - Cursos: contenido modular con lecciones
-- - Artículos: contenido simple (lecciones independientes) con audio
-- - Recursos Educativos: materiales complementarios (PDFs, videos, guías)
-- - Actividades: pueden asociarse tanto a lecciones como a artículos
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABLA BASE: educational_content
-- Almacena cursos y artículos (contenido principal con audio)
-- ----------------------------------------------------------------------------
CREATE TABLE `educational_content` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  
  -- Tipo: 'course' (modular) o 'article' (simple)
  `content_type` enum('course','article') NOT NULL,
  
  -- Información básica
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `thumbnail_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  
  -- Autoría y categorización
  `author_id` bigint UNSIGNED NOT NULL,
  `category_id` bigint UNSIGNED DEFAULT NULL,
  `tags` json DEFAULT NULL,
  
  -- Metadatos educativos
  `difficulty_level` enum('principiante','intermedio','avanzado') 
    COLLATE utf8mb4_unicode_ci DEFAULT 'principiante',
  `estimated_duration` int NOT NULL DEFAULT 0 COMMENT 'En segundos',
  
  -- Estado y publicación
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','pending','reviewed','published') 
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  
  -- Referencias académicas
  `references` json DEFAULT NULL,
  
  -- Métricas generales
  `view_count` int NOT NULL DEFAULT 0,
  `rating_average` decimal(3,2) NOT NULL DEFAULT 0.00,
  `rating_count` int NOT NULL DEFAULT 0,
  
  -- Timestamps
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_slug` (`slug`),
  KEY `idx_content_type` (`content_type`),
  KEY `idx_author` (`author_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_status_published` (`status`, `is_published`),
  KEY `idx_featured` (`is_featured`, `is_published`),
  
  CONSTRAINT `fk_content_author` 
    FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_content_category` 
    FOREIGN KEY (`category_id`) REFERENCES `course_categories`(`id`) ON DELETE SET NULL
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Cursos modulares y artículos (contenido educativo principal)';

-- ----------------------------------------------------------------------------
-- TABLA ESPECÍFICA: course_details
-- Solo campos únicos de CURSOS MODULARES
-- ----------------------------------------------------------------------------
CREATE TABLE `course_details` (
  `id` bigint UNSIGNED NOT NULL,
  
  -- Gamificación
  `completion_points` int NOT NULL DEFAULT 100,
  `achievement_id` bigint UNSIGNED DEFAULT NULL,
  
  -- Taxonomía y ubicación
  `related_taxa` json DEFAULT NULL COMMENT 'IDs de especies relacionadas',
  `target_location_ids` json DEFAULT NULL COMMENT 'Ubicaciones geográficas objetivo',
  
  -- Métricas específicas de cursos
  `enrollment_count` int NOT NULL DEFAULT 0,
  `completion_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  
  -- Certificación
  `has_certificate` tinyint(1) NOT NULL DEFAULT 0,
  
  -- Prerrequisitos
  `prerequisite_content_ids` json DEFAULT NULL COMMENT 'IDs de contenido previo requerido',
  
  PRIMARY KEY (`id`),
  
  CONSTRAINT `fk_course_content` 
    FOREIGN KEY (`id`) REFERENCES `educational_content`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Detalles específicos de cursos modulares';

-- ----------------------------------------------------------------------------
-- TABLA ESPECÍFICA: article_details
-- Solo campos únicos de ARTÍCULOS (lecciones simples independientes)
-- ----------------------------------------------------------------------------
CREATE TABLE `article_details` (
  `id` bigint UNSIGNED NOT NULL,
  
  -- Contenido textual completo
  `content_text` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  
  -- Audio narrado
  `audio_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `audio_timestamps` json DEFAULT NULL COMMENT 'Marcadores de tiempo para navegación',
  `voice_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  
  -- Métricas de lectura
  `read_time` int NOT NULL DEFAULT 0 COMMENT 'Tiempo estimado de lectura en segundos',
  `word_count` int NOT NULL DEFAULT 0,
  
  -- Taxonomía relacionada (para artículos sobre especies específicas)
  `related_taxa` json DEFAULT NULL COMMENT 'IDs de especies mencionadas',
  
  PRIMARY KEY (`id`),
  FULLTEXT KEY `ft_article_content` (`content_text`),
  
  CONSTRAINT `fk_article_content` 
    FOREIGN KEY (`id`) REFERENCES `educational_content`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Detalles de artículos (lecciones simples con audio)';

-- ----------------------------------------------------------------------------
-- TABLA: educational_resources
-- Recursos complementarios (PDFs, videos, guías de campo, etc.)
-- NO confundir con interactive_guides - estos son materiales de apoyo
-- ----------------------------------------------------------------------------
CREATE TABLE `educational_resources` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  
  -- Tipo de recurso
  `resource_type` enum('pdf','video','audio','image','guide','dataset','tool') 
    COLLATE utf8mb4_unicode_ci NOT NULL,
  
  -- Información básica
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `thumbnail_url` text COLLATE utf8mb4_unicode_ci,
  
  -- Contenido
  `content_text` text COLLATE utf8mb4_unicode_ci COMMENT 'Descripción o contenido textual',
  `media_url` text COLLATE utf8mb4_unicode_ci COMMENT 'URL del archivo (PDF, video, etc.)',
  `file_size` bigint DEFAULT NULL COMMENT 'Tamaño en bytes',
  `file_format` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'pdf, mp4, jpg, etc.',
  
  -- Metadatos
  `difficulty_level` enum('principiante','intermedio','avanzado') 
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'principiante',
  `category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `estimated_duration` int DEFAULT NULL COMMENT 'Para videos/audios',
  
  -- Relaciones con contenido educativo
  `related_taxa` json DEFAULT NULL,
  `related_content_ids` json DEFAULT NULL COMMENT 'IDs de educational_content relacionados',
  `prerequisite_content_ids` json DEFAULT NULL,
  
  -- Autoría
  `author_id` bigint UNSIGNED DEFAULT NULL,
  
  -- Estado
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_downloadable` tinyint(1) NOT NULL DEFAULT 1,
  
  -- Métricas
  `view_count` int NOT NULL DEFAULT 0,
  `like_count` int NOT NULL DEFAULT 0,
  `download_count` int NOT NULL DEFAULT 0,
  
  -- Timestamps
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  KEY `idx_resource_type` (`resource_type`),
  KEY `idx_author` (`author_id`),
  KEY `idx_published` (`is_published`),
  
  CONSTRAINT `fk_resource_author` 
    FOREIGN KEY (`author_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Recursos educativos complementarios (PDFs, videos, guías)';

-- ----------------------------------------------------------------------------
-- TABLA: lessons
-- Lecciones que pertenecen a CURSOS (no a artículos)
-- ----------------------------------------------------------------------------
CREATE TABLE `lessons` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  
  -- Relación con curso padre (solo cursos, no artículos)
  `content_id` bigint UNSIGNED NOT NULL,
  
  -- Información básica
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `lesson_order` int NOT NULL,
  `lesson_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  
  -- Contenido
  `content_text` text COLLATE utf8mb4_unicode_ci,
  `media_url` text COLLATE utf8mb4_unicode_ci,
  `media_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  
  -- Audio
  `audio_url` text COLLATE utf8mb4_unicode_ci,
  `audio_timestamps` json DEFAULT NULL,
  `voice_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  
  -- Metadatos
  `estimated_duration` int DEFAULT NULL COMMENT 'En segundos',
  `points` int NOT NULL DEFAULT 10,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  `unlock_requirements` json DEFAULT NULL,
  
  -- Estado
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','pending','reviewed','published') 
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  
  -- Métricas
  `view_count` int NOT NULL DEFAULT 0,
  `completion_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  
  -- Referencias
  `references` json DEFAULT NULL,
  
  -- Timestamps
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  KEY `idx_content_lessons` (`content_id`, `lesson_order`),
  KEY `idx_lesson_status` (`status`, `is_published`),
  
  CONSTRAINT `fk_lesson_content` 
    FOREIGN KEY (`content_id`) REFERENCES `educational_content`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Lecciones que pertenecen a cursos modulares';

-- ----------------------------------------------------------------------------
-- TABLA POLIMÓRFICA: activities
-- Actividades que pueden asociarse a LECCIONES o ARTÍCULOS
-- ¡Esta es la clave para reutilizar actividades!
-- ----------------------------------------------------------------------------
CREATE TABLE `activities` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  
  -- Relación polimórfica: puede ser lesson_id O article_id
  `activitable_id` bigint UNSIGNED NOT NULL COMMENT 'ID de la lección o artículo',
  `activitable_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Lesson o Article',
  
  -- Información básica
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activity_order` int NOT NULL,
  `activity_type` enum(
    'quiz_multiple',
    'quiz_true_false',
    'drag_drop',
    'matching',
    'fill_blanks',
    'image_hotspot',
    'classification',
    'sequencing',
    'memory_game',
    'word_search',
    'crossword'
  ) COLLATE utf8mb4_unicode_ci NOT NULL,
  
  -- Instrucciones y contenido
  `instructions` text COLLATE utf8mb4_unicode_ci,
  `content_data` json NOT NULL COMMENT 'Estructura específica según activity_type',
  `correct_answers` json DEFAULT NULL,
  `hints` json DEFAULT NULL,
  
  -- Configuración de puntuación
  `max_points` int NOT NULL DEFAULT 10,
  `passing_score` int NOT NULL DEFAULT 7,
  `time_limit` int DEFAULT NULL COMMENT 'En segundos',
  `attempts_allowed` int NOT NULL DEFAULT 3,
  
  -- Feedback
  `success_message` text COLLATE utf8mb4_unicode_ci,
  `failure_message` text COLLATE utf8mb4_unicode_ci,
  `explanation` text COLLATE utf8mb4_unicode_ci COMMENT 'Explicación educativa',
  
  -- Configuración
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 1,
  
  -- Timestamps
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  KEY `idx_polymorphic` (`activitable_type`, `activitable_id`),
  KEY `idx_activity_order` (`activitable_id`, `activity_order`),
  KEY `idx_activity_type` (`activity_type`)
  
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Actividades gamificadas asociadas a lecciones o artículos (polimórfica)';

-- ----------------------------------------------------------------------------
-- TABLA: user_content_enrollments
-- Inscripciones genéricas (cursos y artículos)
-- ----------------------------------------------------------------------------
CREATE TABLE `user_content_enrollments` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  
  -- Relaciones
  `user_id` bigint UNSIGNED NOT NULL,
  `content_id` bigint UNSIGNED NOT NULL,
  
  -- Fechas de progreso
  `enrolled_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  
  -- Progreso en lecciones (solo para cursos con lecciones)
  `current_lesson_id` bigint UNSIGNED DEFAULT NULL,
  `lessons_completed` int NOT NULL DEFAULT 0,
  `total_lessons` int NOT NULL DEFAULT 0,
  `progress_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  
  -- Puntuación
  `total_points_earned` int NOT NULL DEFAULT 0,
  `total_points_possible` int NOT NULL DEFAULT 0,
  `final_score` decimal(5,2) DEFAULT NULL,
  
  -- Tiempo invertido
  `total_time_spent` int NOT NULL DEFAULT 0 COMMENT 'En segundos',
  
  -- Feedback del usuario
  `user_rating` int DEFAULT NULL,
  `user_feedback` text COLLATE utf8mb4_unicode_ci,
  
  -- Timestamps
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_content` (`user_id`, `content_id`),
  KEY `idx_user_enrollments` (`user_id`, `completed_at`),
  KEY `idx_content_enrollments` (`content_id`),
  
  CONSTRAINT `fk_enrollment_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enrollment_content` 
    FOREIGN KEY (`content_id`) REFERENCES `educational_content`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Inscripciones de usuarios a cursos y artículos';

-- ----------------------------------------------------------------------------
-- TABLA: user_lesson_progress
-- Progreso en lecciones específicas (solo para cursos)
-- ----------------------------------------------------------------------------
CREATE TABLE `user_lesson_progress` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `lesson_id` bigint UNSIGNED NOT NULL,
  `enrollment_id` bigint UNSIGNED NOT NULL,
  `status` enum('no_iniciada','en_progreso','completada','bloqueada') 
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no_iniciada',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `activities_completed` int NOT NULL DEFAULT 0,
  `total_activities` int NOT NULL DEFAULT 0,
  `points_earned` int NOT NULL DEFAULT 0,
  `points_possible` int NOT NULL DEFAULT 0,
  `time_spent` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_lesson` (`user_id`, `lesson_id`),
  KEY `idx_enrollment` (`enrollment_id`),
  
  CONSTRAINT `fk_lesson_progress_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lesson_progress_lesson` 
    FOREIGN KEY (`lesson_id`) REFERENCES `lessons`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lesson_progress_enrollment` 
    FOREIGN KEY (`enrollment_id`) REFERENCES `user_content_enrollments`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- TABLA: user_activity_attempts
-- Intentos de actividades (tanto de lecciones como de artículos)
-- ----------------------------------------------------------------------------
CREATE TABLE `user_activity_attempts` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `activity_id` bigint UNSIGNED NOT NULL,
  
  -- Referencia al progreso (puede ser de lección o de artículo)
  `progress_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'LessonProgress o ArticleProgress',
  `progress_id` bigint UNSIGNED NOT NULL COMMENT 'ID del progreso correspondiente',
  
  -- Información del intento
  `attempt_number` int NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` timestamp NULL DEFAULT NULL,
  `user_answers` json DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `points_earned` int NOT NULL DEFAULT 0,
  `time_taken` int DEFAULT NULL COMMENT 'En segundos',
  `hints_used` json DEFAULT NULL,
  
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  KEY `idx_user_activity` (`user_id`, `activity_id`),
  KEY `idx_activity` (`activity_id`),
  KEY `idx_progress_polymorphic` (`progress_type`, `progress_id`),
  
  CONSTRAINT `fk_attempt_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attempt_activity` 
    FOREIGN KEY (`activity_id`) REFERENCES `activities`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Intentos de actividades por usuario';

-- ----------------------------------------------------------------------------
-- TABLA: user_article_progress
-- Progreso en artículos (similar a lesson_progress pero para artículos)
-- ----------------------------------------------------------------------------
CREATE TABLE `user_article_progress` (
  `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` bigint UNSIGNED NOT NULL,
  `article_id` bigint UNSIGNED NOT NULL COMMENT 'ID del educational_content tipo article',
  `enrollment_id` bigint UNSIGNED NOT NULL,
  `status` enum('no_iniciada','en_progreso','completada') 
    COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'no_iniciada',
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  
  -- Progreso de lectura
  `reading_progress` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Porcentaje leído',
  `last_position` int DEFAULT NULL COMMENT 'Posición del scroll/audio',
  
  -- Actividades (si el artículo tiene)
  `activities_completed` int NOT NULL DEFAULT 0,
  `total_activities` int NOT NULL DEFAULT 0,
  `points_earned` int NOT NULL DEFAULT 0,
  `points_possible` int NOT NULL DEFAULT 0,
  
  `time_spent` int NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_article` (`user_id`, `article_id`),
  KEY `idx_enrollment` (`enrollment_id`),
  
  CONSTRAINT `fk_article_progress_user` 
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_progress_article` 
    FOREIGN KEY (`article_id`) REFERENCES `educational_content`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_article_progress_enrollment` 
    FOREIGN KEY (`enrollment_id`) REFERENCES `user_content_enrollments`(`id`) ON DELETE CASCADE
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Progreso de usuarios en artículos';

-- ============================================================================
-- ÍNDICES ADICIONALES PARA OPTIMIZACIÓN
-- ============================================================================

ALTER TABLE `educational_content` 
  ADD FULLTEXT KEY `ft_title_description` (`title`, `description`);

-- ============================================================================
-- TRIGGERS PARA MANTENER INTEGRIDAD
-- ============================================================================

DELIMITER $$

-- Validar que solo cursos tengan lecciones
CREATE TRIGGER `validate_course_has_lessons`
BEFORE INSERT ON `lessons`
FOR EACH ROW
BEGIN
  DECLARE content_type_val VARCHAR(50);
  
  SELECT content_type INTO content_type_val
  FROM educational_content
  WHERE id = NEW.content_id;
  
  IF content_type_val != 'course' THEN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Solo los cursos pueden tener lecciones';
  END IF;
END$$

-- Actualizar enrollment_count al inscribirse
CREATE TRIGGER `update_enrollment_count_insert`
AFTER INSERT ON `user_content_enrollments`
FOR EACH ROW
BEGIN
  DECLARE content_type_val VARCHAR(50);
  
  SELECT content_type INTO content_type_val
  FROM educational_content
  WHERE id = NEW.content_id;
  
  IF content_type_val = 'course' THEN
    UPDATE course_details 
    SET enrollment_count = enrollment_count + 1
    WHERE id = NEW.content_id;
  END IF;
END$$

-- Actualizar enrollment_count al desinscribirse
CREATE TRIGGER `update_enrollment_count_delete`
AFTER DELETE ON `user_content_enrollments`
FOR EACH ROW
BEGIN
  UPDATE course_details 
  SET enrollment_count = enrollment_count - 1
  WHERE id = OLD.content_id AND enrollment_count > 0;
END$$

DELIMITER ;

-- ============================================================================
-- FIN DE LA ARQUITECTURA OPTIMIZADA V2
-- ============================================================================