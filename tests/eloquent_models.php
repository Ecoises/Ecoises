<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Modelo base unificado para contenido educativo principal
 * Incluye: Cursos modulares y Artículos (lecciones simples)
 */
class EducationalContent extends Model
{
    use HasFactory;

    protected $table = 'educational_content';

    // Tipos de contenido
    public const TYPE_COURSE = 'course';
    public const TYPE_ARTICLE = 'article';

    // Estados
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_PUBLISHED = 'published';

    // Niveles de dificultad
    public const DIFFICULTY_BEGINNER = 'principiante';
    public const DIFFICULTY_INTERMEDIATE = 'intermedio';
    public const DIFFICULTY_ADVANCED = 'avanzado';

    protected $fillable = [
        'content_type',
        'title',
        'slug',
        'description',
        'thumbnail_url',
        'author_id',
        'category_id',
        'tags',
        'difficulty_level',
        'estimated_duration',
        'is_published',
        'is_featured',
        'status',
        'references',
        'view_count',
        'rating_average',
        'rating_count',
    ];

    protected $casts = [
        'tags' => 'array',
        'references' => 'array',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'estimated_duration' => 'integer',
        'view_count' => 'integer',
        'rating_average' => 'decimal:2',
        'rating_count' => 'integer',
    ];

    protected $attributes = [
        'content_type' => self::TYPE_COURSE,
        'difficulty_level' => self::DIFFICULTY_BEGINNER,
        'status' => self::STATUS_DRAFT,
        'is_published' => false,
        'is_featured' => false,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($content) {
            if (empty($content->slug)) {
                $content->slug = Str::slug($content->title);
            }
        });

        // Al eliminar contenido, eliminar también sus detalles específicos
        static::deleting(function ($content) {
            if ($content->isCourse() && $content->courseDetails) {
                $content->courseDetails->delete();
            } elseif ($content->isArticle() && $content->articleDetails) {
                $content->articleDetails->delete();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relaciones Básicas
    |--------------------------------------------------------------------------
    */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CourseCategory::class, 'category_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(UserContentEnrollment::class, 'content_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Relaciones Específicas por Tipo
    |--------------------------------------------------------------------------
    */
    
    // Solo para CURSOS
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'content_id')->orderBy('lesson_order');
    }

    public function courseDetails(): HasOne
    {
        return $this->hasOne(CourseDetails::class, 'id');
    }

    // Solo para ARTÍCULOS
    public function articleDetails(): HasOne
    {
        return $this->hasOne(ArticleDetails::class, 'id');
    }

    /**
     * Las actividades solo para ARTÍCULOS (los cursos tienen actividades en sus lecciones)
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activitable')->orderBy('activity_order');
    }

    /**
     * Obtiene los detalles específicos según el tipo de contenido
     */
    public function details()
    {
        return match($this->content_type) {
            self::TYPE_COURSE => $this->courseDetails(),
            self::TYPE_ARTICLE => $this->articleDetails(),
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */
    public function scopePublished($query)
    {
        return $query->where('is_published', true)
                     ->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('content_type', $type);
    }

    public function scopeCourses($query)
    {
        return $query->ofType(self::TYPE_COURSE);
    }

    public function scopeArticles($query)
    {
        return $query->ofType(self::TYPE_ARTICLE);
    }

    /*
    |--------------------------------------------------------------------------
    | Métodos de Ayuda
    |--------------------------------------------------------------------------
    */
    public function isCourse(): bool
    {
        return $this->content_type === self::TYPE_COURSE;
    }

    public function isArticle(): bool
    {
        return $this->content_type === self::TYPE_ARTICLE;
    }

    public function hasLessons(): bool
    {
        return $this->isCourse();
    }

    public function canHaveActivities(): bool
    {
        return $this->isArticle(); // Solo artículos tienen actividades directas
    }

    public function getFormattedDuration(): string
    {
        $seconds = $this->estimated_duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /*
    |--------------------------------------------------------------------------
    | Métodos Estáticos de Utilidad
    |--------------------------------------------------------------------------
    */
    public static function getTypes(): array
    {
        return [
            self::TYPE_COURSE => 'Curso modular',
            self::TYPE_ARTICLE => 'Lección simple',
        ];
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_DRAFT => 'Borrador',
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_REVIEWED => 'Revisado',
            self::STATUS_PUBLISHED => 'Publicado',
        ];
    }

    public static function getDifficultyLevels(): array
    {
        return [
            self::DIFFICULTY_BEGINNER => 'Principiante',
            self::DIFFICULTY_INTERMEDIATE => 'Intermedio',
            self::DIFFICULTY_ADVANCED => 'Avanzado',
        ];
    }
}

/**
 * Detalles específicos de CURSOS
 */
class CourseDetails extends Model
{
    use HasFactory;

    protected $table = 'course_details';
    public $timestamps = false;
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'completion_points',
        'achievement_id',
        'related_taxa',
        'target_location_ids',
        'enrollment_count',
        'completion_rate',
        'has_certificate',
        'prerequisite_content_ids',
    ];

    protected $casts = [
        'related_taxa' => 'array',
        'target_location_ids' => 'array',
        'prerequisite_content_ids' => 'array',
        'completion_points' => 'integer',
        'enrollment_count' => 'integer',
        'completion_rate' => 'decimal:2',
        'has_certificate' => 'boolean',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'id');
    }
}

/**
 * Detalles específicos de ARTÍCULOS
 */
class ArticleDetails extends Model
{
    use HasFactory;

    protected $table = 'article_details';
    public $timestamps = false;
    protected $primaryKey = 'id';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'content_text',
        'audio_url',
        'audio_timestamps',
        'voice_id',
        'read_time',
        'word_count',
        'related_taxa',
    ];

    protected $casts = [
        'audio_timestamps' => 'array',
        'related_taxa' => 'array',
        'read_time' => 'integer',
        'word_count' => 'integer',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'id');
    }

    /**
     * Calcula el tiempo de lectura estimado basado en el contenido
     */
    public static function estimateReadingTime(string $text): int
    {
        $cleanText = strip_tags($text);
        $wordCount = str_word_count($cleanText);
        $wordsPerMinute = 200; // Promedio de lectura
        $minutes = ceil($wordCount / $wordsPerMinute);
        
        return $minutes * 60; // Retorna en segundos
    }
}

/**
 * Recursos educativos complementarios
 * PDFs, videos, guías de campo, datasets, etc.
 */
class EducationalResource extends Model
{
    use HasFactory;

    protected $table = 'educational_resources';

    // Tipos de recursos
    public const TYPE_PDF = 'pdf';
    public const TYPE_VIDEO = 'video';
    public const TYPE_AUDIO = 'audio';
    public const TYPE_IMAGE = 'image';
    public const TYPE_GUIDE = 'guide';
    public const TYPE_DATASET = 'dataset';
    public const TYPE_TOOL = 'tool';

    protected $fillable = [
        'resource_type',
        'title',
        'description',
        'thumbnail_url',
        'content_text',
        'media_url',
        'file_size',
        'file_format',
        'difficulty_level',
        'category',
        'tags',
        'estimated_duration',
        'related_taxa',
        'related_content_ids',
        'prerequisite_content_ids',
        'author_id',
        'is_published',
        'is_featured',
        'is_downloadable',
        'view_count',
        'like_count',
        'download_count',
    ];

    protected $casts = [
        'tags' => 'array',
        'related_taxa' => 'array',
        'related_content_ids' => 'array',
        'prerequisite_content_ids' => 'array',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'is_downloadable' => 'boolean',
        'file_size' => 'integer',
        'estimated_duration' => 'integer',
        'view_count' => 'integer',
        'like_count' => 'integer',
        'download_count' => 'integer',
    ];

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public static function getResourceTypes(): array
    {
        return [
            self::TYPE_PDF => 'PDF',
            self::TYPE_VIDEO => 'Video',
            self::TYPE_AUDIO => 'Audio',
            self::TYPE_IMAGE => 'Imagen',
            self::TYPE_GUIDE => 'Guía de Campo',
            self::TYPE_DATASET => 'Conjunto de Datos',
            self::TYPE_TOOL => 'Herramienta',
        ];
    }
}

/**
 * Lección - solo pertenece a CURSOS (no a artículos)
 */
class Lesson extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_id',
        'title',
        'description',
        'lesson_order',
        'lesson_type',
        'content_text',
        'media_url',
        'media_type',
        'audio_url',
        'audio_timestamps',
        'voice_id',
        'estimated_duration',
        'points',
        'is_mandatory',
        'unlock_requirements',
        'is_published',
        'status',
        'view_count',
        'completion_rate',
        'references',
    ];

    protected $casts = [
        'audio_timestamps' => 'array',
        'unlock_requirements' => 'array',
        'references' => 'array',
        'is_mandatory' => 'boolean',
        'is_published' => 'boolean',
        'estimated_duration' => 'integer',
        'points' => 'integer',
        'view_count' => 'integer',
        'completion_rate' => 'decimal:2',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'content_id');
    }

    /**
     * Actividades de la lección (relación polimórfica)
     */
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activitable')->orderBy('activity_order');
    }

    public function progress(): HasMany
    {
        return $this->hasMany(UserLessonProgress::class);
    }

    /**
     * Estima el tiempo de lectura del contenido
     */
    public static function estimateReadingTime(string $text): int
    {
        return ArticleDetails::estimateReadingTime($text);
    }
}

/**
 * Actividad gamificada - POLIMÓRFICA
 * Puede asociarse a Lesson (de cursos) o a EducationalContent (artículos)
 */
class Activity extends Model
{
    use HasFactory;

    protected $table = 'activities';

    // Tipos de actividades
    public const TYPE_QUIZ_MULTIPLE = 'quiz_multiple';
    public const TYPE_QUIZ_TRUE_FALSE = 'quiz_true_false';
    public const TYPE_DRAG_DROP = 'drag_drop';
    public const TYPE_MATCHING = 'matching';
    public const TYPE_FILL_BLANKS = 'fill_blanks';
    public const TYPE_IMAGE_HOTSPOT = 'image_hotspot';
    public const TYPE_CLASSIFICATION = 'classification';
    public const TYPE_SEQUENCING = 'sequencing';
    public const TYPE_MEMORY_GAME = 'memory_game';
    public const TYPE_WORD_SEARCH = 'word_search';
    public const TYPE_CROSSWORD = 'crossword';

    protected $fillable = [
        'activitable_id',
        'activitable_type',
        'title',
        'activity_order',
        'activity_type',
        'instructions',
        'content_data',
        'correct_answers',
        'hints',
        'max_points',
        'passing_score',
        'time_limit',
        'attempts_allowed',
        'success_message',
        'failure_message',
        'explanation',
        'is_mandatory',
    ];

    protected $casts = [
        'content_data' => 'array',
        'correct_answers' => 'array',
        'hints' => 'array',
        'is_mandatory' => 'boolean',
        'max_points' => 'integer',
        'passing_score' => 'integer',
        'time_limit' => 'integer',
        'attempts_allowed' => 'integer',
    ];

    /**
     * Relación polimórfica - puede ser Lesson o EducationalContent (artículo)
     */
    public function activitable()
    {
        return $this->morphTo();
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(UserActivityAttempt::class);
    }

    public static function getActivityTypes(): array
    {
        return [
            self::TYPE_QUIZ_MULTIPLE => 'Selección múltiple',
            self::TYPE_QUIZ_TRUE_FALSE => 'Verdadero/Falso',
            self::TYPE_DRAG_DROP => 'Arrastrar y soltar',
            self::TYPE_MATCHING => 'Emparejar',
            self::TYPE_FILL_BLANKS => 'Completar espacios',
            self::TYPE_IMAGE_HOTSPOT => 'Puntos calientes en imagen',
            self::TYPE_CLASSIFICATION => 'Clasificación',
            self::TYPE_SEQUENCING => 'Secuenciación',
            self::TYPE_MEMORY_GAME => 'Juego de memoria',
            self::TYPE_WORD_SEARCH => 'Sopa de letras',
            self::TYPE_CROSSWORD => 'Crucigrama',
        ];
    }
}

/**
 * Inscripción de usuario a contenido educativo
 */
class UserContentEnrollment extends Model
{
    use HasFactory;

    protected $table = 'user_content_enrollments';

    protected $fillable = [
        'user_id',
        'content_id',
        'enrolled_at',
        'started_at',
        'completed_at',
        'last_accessed_at',
        'current_lesson_id',
        'lessons_completed',
        'total_lessons',
        'progress_percentage',
        'total_points_earned',
        'total_points_possible',
        'final_score',
        'total_time_spent',
        'user_rating',
        'user_feedback',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'lessons_completed' => 'integer',
        'total_lessons' => 'integer',
        'progress_percentage' => 'decimal:2',
        'total_points_earned' => 'integer',
        'total_points_possible' => 'integer',
        'final_score' => 'decimal:2',
        'total_time_spent' => 'integer',
        'user_rating' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'content_id');
    }

    public function currentLesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class, 'current_lesson_id');
    }

    public function lessonProgress(): HasMany
    {
        return $this->hasMany(UserLessonProgress::class, 'enrollment_id');
    }

    public function articleProgress(): HasOne
    {
        return $this->hasOne(UserArticleProgress::class, 'enrollment_id');
    }

    public function isCompleted(): bool
    {
        return !is_null($this->completed_at);
    }
}

/**
 * Progreso del usuario en una lección específica
 */
class UserLessonProgress extends Model
{
    use HasFactory;

    protected $table = 'user_lesson_progress';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'enrollment_id',
        'status',
        'started_at',
        'completed_at',
        'last_accessed_at',
        'activities_completed',
        'total_activities',
        'points_earned',
        'points_possible',
        'time_spent',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'activities_completed' => 'integer',
        'total_activities' => 'integer',
        'points_earned' => 'integer',
        'points_possible' => 'integer',
        'time_spent' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(UserContentEnrollment::class, 'enrollment_id');
    }
}

/**
 * Progreso del usuario en un artículo
 */
class UserArticleProgress extends Model
{
    use HasFactory;

    protected $table = 'user_article_progress';

    protected $fillable = [
        'user_id',
        'article_id',
        'enrollment_id',
        'status',
        'started_at',
        'completed_at',
        'last_accessed_at',
        'reading_progress',
        'last_position',
        'activities_completed',
        'total_activities',
        'points_earned',
        'points_possible',
        'time_spent',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'reading_progress' => 'decimal:2',
        'last_position' => 'integer',
        'activities_completed' => 'integer',
        'total_activities' => 'integer',
        'points_earned' => 'integer',
        'points_possible' => 'integer',
        'time_spent' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'article_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(UserContentEnrollment::class, 'enrollment_id');
    }
}

/**
 * Intento de actividad por usuario
 */
class UserActivityAttempt extends Model
{
    use HasFactory;

    protected $table = 'user_activity_attempts';

    protected $fillable = [
        'user_id',
        'activity_id',
        'progress_type',
        'progress_id',
        'attempt_number',
        'started_at',
        'completed_at',
        'user_answers',
        'is_correct',
        'points_earned',
        'time_taken',
        'hints_used',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'user_answers' => 'array',
        'hints_used' => 'array',
        'is_correct' => 'boolean',
        'attempt_number' => 'integer',
        'points_earned' => 'integer',
        'time_taken' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}