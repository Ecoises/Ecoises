<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnifiedApiCache extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'cache_key',
        'cache_type',
        'taxon_id',
        'user_id',
        'api_source',
        'data_type',
        'request_url',
        'request_params',
        'response_data',
        'response_metadata',
        'expires_at',
        'last_accessed_at',
        'hit_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'request_params' => 'array',
        'response_data' => 'array',
        'response_metadata' => 'array',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
    ];

    // Relaciones

    public function taxon(): BelongsTo
    {
        return $this->belongsTo(Taxa::class);
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}