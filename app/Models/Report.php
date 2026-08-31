<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    use HasFactory;

    public const TYPE_OBSERVATION = 'observation_report';

    public const TYPE_CONTENT_FEEDBACK = 'content_feedback';

    public const TYPE_GENERAL_FEEDBACK = 'general_feedback';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_DISMISSED = 'dismissed';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'observation_id',
        'reportable_type',
        'reportable_id',
        'type',
        'category',
        'subject',
        'comment',
        'status',
        'priority',
        'assigned_to',
        'resolved_by',
        'first_reviewed_at',
        'resolved_at',
        'resolution_notes',
        'metadata',
    ];

    protected $casts = [
        'first_reviewed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Report $report): void {
            if (! $report->isDirty('status')) {
                return;
            }

            if ($report->status === self::STATUS_IN_REVIEW && $report->first_reviewed_at === null) {
                $report->first_reviewed_at = now();
            }

            if (in_array($report->status, [self::STATUS_RESOLVED, self::STATUS_DISMISSED], true)) {
                $report->resolved_at ??= now();
                $report->resolved_by ??= auth()->id();
            } else {
                $report->resolved_at = null;
                $report->resolved_by = null;
            }
        });
    }

    /**
     * Relación con el usuario que reportó.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con la observación reportada.
     */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_REVIEW]);
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_OBSERVATION => 'Reporte de observación',
            self::TYPE_CONTENT_FEEDBACK => 'Feedback educativo',
            self::TYPE_GENERAL_FEEDBACK => 'Sugerencia o incidencia',
        ];
    }

    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_IN_REVIEW => 'En revisión',
            self::STATUS_RESOLVED => 'Resuelto',
            self::STATUS_DISMISSED => 'Descartado',
        ];
    }

    public static function getPriorities(): array
    {
        return [
            self::PRIORITY_LOW => 'Baja',
            self::PRIORITY_NORMAL => 'Normal',
            self::PRIORITY_HIGH => 'Alta',
            self::PRIORITY_URGENT => 'Urgente',
        ];
    }
}
