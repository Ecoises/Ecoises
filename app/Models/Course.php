<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Course extends EducationalContent
{
    use HasFactory;

    protected $table = 'educational_content';

    protected static function booted()
    {
        static::addGlobalScope('course', function (Builder $builder) {
            $builder->where('content_type', 'course');
        });

        static::creating(function ($course) {
            $course->content_type = 'course';
        });
        
        static::created(function ($course) {
            if (!$course->details) {
                $course->details()->create([]);
            }
        });
    }

    public function details(): HasOne
    {
        return $this->hasOne(CourseDetails::class, 'id');
    }

    // Proxy attributes
    public function getCompletionPointsAttribute()
    {
        return $this->details->completion_points ?? 100;
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'content_id');
    }
    
    public function enrollments(): HasMany
    {
        return $this->hasMany(UserContentEnrollment::class, 'content_id');
    }
}