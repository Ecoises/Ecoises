<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationalContentAsset extends Model
{
    use HasFactory;

    public const TYPE_IMAGE = 'image';

    public const TYPE_INFOGRAPHIC = 'infographic';

    public const TYPE_DOCUMENT = 'document';

    public const TYPE_EXTERNAL_LINK = 'external_link';

    protected $fillable = [
        'content_id',
        'asset_type',
        'title',
        'description',
        'file_path',
        'external_url',
        'is_downloadable',
        'asset_order',
    ];

    protected $casts = [
        'is_downloadable' => 'boolean',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'content_id');
    }

    public static function getTypes(): array
    {
        return [
            self::TYPE_IMAGE => 'Imagen',
            self::TYPE_INFOGRAPHIC => 'Infografía',
            self::TYPE_DOCUMENT => 'Documento o PDF',
            self::TYPE_EXTERNAL_LINK => 'Enlace externo',
        ];
    }
}
