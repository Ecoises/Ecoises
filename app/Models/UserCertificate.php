<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'verification_code',
        'user_id',
        'content_id',
        'enrollment_id',
        'final_score',
        'issued_at',
        'revoked_at',
    ];

    protected $casts = [
        'final_score' => 'decimal:2',
        'issued_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(EducationalContent::class, 'content_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(UserContentEnrollment::class, 'enrollment_id');
    }
}
