<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationPhoto extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'observation_id',
        'photo_url',
        'is_primary',
        'caption',
        'photo_order',
        'file_size',
        'image_width',
        'image_height',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_primary' => 'boolean',
    ];

    // Relaciones

    public function observation(): BelongsTo
    {
        return $this->belongsTo(Observation::class);
    }
}