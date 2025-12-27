<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticleDetails extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'article_details';
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
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'id');
    }
}
