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
        'word_count',
        'related_taxa',
        'references',
    ];

    protected $casts = [
        'audio_timestamps' => 'array',
        'related_taxa' => 'array',
        'references' => 'array',
    ];

    // App\Models\ArticleDetails.php

    protected static function booted()
    {
        static::saving(function ($details) {
            // Al guardar un Artículo, actualiza su propio tiempo
            if ($details->isDirty('content_text')) {
                $words = str_word_count(strip_tags($details->content_text));
                $details->word_count = $words;
                $details->read_time = (int) ceil($words / 200);
            }
        });

        static::saved(function ($details) {
            // Y actualiza el tiempo de su padre (EducationalContent)
            if ($details->content) {
                $details->content->update([
                    'estimated_duration' => $details->read_time
                ]);
            }
        });
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'id');
    }
}
