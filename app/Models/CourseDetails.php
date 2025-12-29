<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseDetails extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $table = 'course_details';
    public $incrementing = false; // PK is shared with educational_content

    protected $fillable = [
        'id',
        'completion_points',
        'achievement_id',
        'enrollment_count',
        'completion_rate',
        'has_certificate',
        'prerequisite_content_ids',
    ];

    protected $casts = [
        'prerequisite_content_ids' => 'array',
        'has_certificate' => 'boolean',
    ];

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'id');
    }
}
