<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Article extends EducationalContent
{
    use HasFactory;

    protected $table = 'educational_content';

    protected static function booted()
    {
        static::addGlobalScope('article', function (Builder $builder) {
            $builder->where('content_type', 'article');
        });

        static::creating(function ($article) {
            $article->content_type = 'article';
        });

        static::created(function ($article) {
            if (!$article->articleDetails) {
                // Use 'articleDetails' relation name from parent
                $article->articleDetails()->create([]);
            }
        });
    }

    public function details(): HasOne
    {
        return $this->hasOne(ArticleDetails::class, 'id');
    }

    // Related Progress
    public function progress(): HasMany
    {
         return $this->hasMany(UserArticleProgress::class, 'article_id');
    }
}
