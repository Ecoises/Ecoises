<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserArticleProgress extends Model
{
    use HasFactory;

    protected $table = 'user_article_progress';

    protected $fillable = [
        'user_id',
        'article_id',
        'enrollment_id',
        'status', // 'no_iniciada', 'en_progreso', 'completada'
        'started_at',
        'completed_at',
        'last_accessed_at',
        'reading_progress', // percentage or scroll position
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
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(UserContentEnrollment::class, 'enrollment_id');
    }
}
