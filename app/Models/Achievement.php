<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Achievement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'icon_url',
        'category',
        'points',
        'requirement_type',
        'requirement_criteria',
        'is_active',
        'rarity',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'requirement_criteria' => 'array',
        'is_active' => 'boolean',
    ];

    // Relaciones

    public function userAchievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }
}
