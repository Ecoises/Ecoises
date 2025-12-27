<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    use HasFactory;

    protected $table = 'activities';

    protected $fillable = [
        'activitable_id',
        'activitable_type',
        'title',
        'activity_order',
        'activity_type',
        'instructions',
        'content_data',
        'correct_answers',
        'hints',
        'max_points',
        'passing_score',
        'time_limit',
        'attempts_allowed',
        'success_message',
        'failure_message',
        'explanation',
        'is_mandatory',
    ];

    protected $casts = [
        'content_data' => 'array',
        'correct_answers' => 'array',
        'hints' => 'array',
        'is_mandatory' => 'boolean',
    ];

    public function activitable(): MorphTo
    {
        return $this->morphTo();
    }
}
