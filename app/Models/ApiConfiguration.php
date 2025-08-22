<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiConfiguration extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'api_source',
        'base_url',
        'api_key_required',
        'rate_limit_requests',
        'rate_limit_period',
        'daily_limit',
        'monthly_limit',
        'cache_ttl_description',
        'cache_ttl_images',
        'cache_ttl_sounds',
        'cache_ttl_distribution',
        'cache_ttl_conservation',
        'cache_ttl_characteristics',
        'cache_ttl_references',
        'is_active',
        'last_health_check',
        'health_status',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'api_key_required' => 'boolean',
        'is_active' => 'boolean',
        'last_health_check' => 'datetime',
    ];
}