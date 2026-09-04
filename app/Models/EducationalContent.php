<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EducationalContent extends Model
{
    use HasFactory;

    protected $table = 'educational_content';

    // Content Types
    const TYPE_COURSE = 'course';

    const TYPE_ARTICLE = 'article';

    const TYPE_RESOURCE = 'resource';

    // Statuses
    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending'; // Added pending as referenced in snippet

    const STATUS_REVIEWED = 'reviewed';

    const STATUS_PUBLISHED = 'published';

    // Difficulty Levels
    const DIFFICULTY_BEGINNER = 'principiante';

    const DIFFICULTY_INTERMEDIATE = 'intermedio';

    const DIFFICULTY_ADVANCED = 'avanzado';

    protected $fillable = [
        'content_type',
        'title',
        'slug',
        'description',
        'thumbnail_url',
        'author_id',
        'tags',
        'difficulty_level',
        'estimated_duration',
        'is_published',
        'is_featured',
        'status',
        'published_at',
        'view_count',
        'rating_average',
        'rating_count',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_published' => 'boolean',
        'is_featured' => 'boolean',
        'published_at' => 'datetime',
        'rating_average' => 'decimal:2',
        'rating_count' => 'integer',
    ];

    // Relationships

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ContentCategory::class, 'category_content', 'content_id', 'category_id');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activitable')->orderBy('activity_order');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'content_id')->orderBy('lesson_order');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(EducationalContentAsset::class, 'content_id')->orderBy('asset_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EducationalContentVersion::class, 'content_id')->latest('version_number');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(UserCertificate::class, 'content_id');
    }

    // Detail Relationships
    public function courseDetails(): HasOne
    {
        return $this->hasOne(CourseDetails::class, 'id');
    }

    public function articleDetails(): HasOne
    {
        return $this->hasOne(ArticleDetails::class, 'id');
    }

    // Common Helpers
    public function isCourse(): bool
    {
        return $this->content_type === self::TYPE_COURSE;
    }

    public function isArticle(): bool
    {
        return $this->content_type === self::TYPE_ARTICLE;
    }

    public function isResource(): bool
    {
        return $this->content_type === self::TYPE_RESOURCE;
    }

    // Static Helpers for Filament
    public static function getTypes(): array
    {
        return [
            self::TYPE_COURSE => 'Curso Modular',
            self::TYPE_ARTICLE => 'Artículo Simple',
            self::TYPE_RESOURCE => 'Recurso Multimedia',
        ];
    }

    public static function getTypeValues(): array
    {
        return array_keys(self::getTypes());
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->published_at !== null
            && $this->published_at->lessThanOrEqualTo(now());
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
