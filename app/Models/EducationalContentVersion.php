<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationalContentVersion extends Model
{
    use HasFactory;

    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_REVIEWED = 'reviewed';

    public const EVENT_RETURNED = 'returned';

    public const EVENT_PUBLISHED = 'published';

    public const EVENT_UNPUBLISHED = 'unpublished';

    protected $fillable = [
        'content_id',
        'version_number',
        'created_by',
        'event',
        'change_summary',
        'snapshot_hash',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'version_number' => 'integer',
    ];

    protected $hidden = ['snapshot'];

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'content_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function getEvents(): array
    {
        return [
            self::EVENT_SUBMITTED => 'Enviado a revisión',
            self::EVENT_REVIEWED => 'Revisión aprobada',
            self::EVENT_RETURNED => 'Devuelto a borrador',
            self::EVENT_PUBLISHED => 'Publicado',
            self::EVENT_UNPUBLISHED => 'Retirado de publicación',
        ];
    }
}
