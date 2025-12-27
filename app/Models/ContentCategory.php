<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentCategory extends Model
{
    use HasFactory;

    protected $table = 'content_categories';

    protected $fillable = [
        'name',
        'slug',
        'description',
    ];

    public function content(): BelongsToMany
    {
        return $this->belongsToMany(EducationalContent::class, 'category_content', 'category_id', 'content_id');
    }
}
