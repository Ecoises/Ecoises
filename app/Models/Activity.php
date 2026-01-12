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

    protected $appends = [
        'options',
        'is_true',
        'correct_answer',
        'true_false_feedback',
        'items',
        'pairs',
    ];

    public function activitable(): MorphTo
    {
        return $this->morphTo();
    }

    // Accessors mejorados

    public function getOptionsAttribute()
    {
        return $this->content_data['options'] ?? null;
    }

    public function getIsTrueAttribute()
    {
        $value = $this->content_data['is_true'] ?? null;
        
        // Convertir string a boolean si es necesario
        if ($value === 'true') return true;
        if ($value === 'false') return false;
        
        return $value;
    }

    public function getCorrectAnswerAttribute()
    {
        // Para True/False
        if ($this->activity_type === 'quiz_true_false') {
            $value = $this->content_data['is_true'] ?? null;
            
            // Convertir string a boolean si es necesario (retrocompatibilidad)
            if ($value === 'true') return true;
            if ($value === 'false') return false;
            
            return $value;
        }
        
        // Para Multiple Choice
        if ($this->activity_type === 'quiz_multiple') {
            return $this->content_data['options'] ?? null;
        }
        
        // Para otros tipos
        return $this->correct_answers;
    }

    public function getTrueFalseFeedbackAttribute()
    {
        return $this->content_data['true_false_feedback'] ?? null;
    }

    public function getItemsAttribute()
    {
        return $this->content_data['items'] ?? null;
    }

    public function getPairsAttribute()
    {
        return $this->content_data['pairs'] ?? null;
    }
}