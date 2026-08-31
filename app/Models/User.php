<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword as CanResetPasswordTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use CanResetPasswordTrait, hasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $appends = ['name'];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'email',
        'password',
        'google_id',
        'avatar',
        'bio',
        'total_score',
        'level',
        'experience_points',
        'is_active',
        'last_activity_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\CustomResetPasswordNotification($token));
    }

    public function observations(): HasMany
    {
        return $this->hasMany(Observation::class);
    }

    public function identifications(): HasMany
    {
        return $this->hasMany(Identification::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function currentLevel(): BelongsTo
    {
        return $this->belongsTo(Level::class, 'level');
    }

    public function pointTransactions(): HasMany
    {
        return $this->hasMany(PointTransaction::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function getFilamentName(): string
    {
        // Usamos el campo 'full_name' que tienes en tu base de datos.
        // El operador ?? 'Usuario' asegura que siempre devuelva un string
        // en caso de que 'full_name' esté nulo en algún registro.
        return $this->full_name ?? $this->email;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return (bool) $this->is_active
            && $this->hasAnyRole(['super_admin', 'editor', 'educador', 'moderador']);
    }

    public function getNameAttribute(): ?string
    {
        return $this->full_name;
    }

    // New/Updated Relations for CTI

    public function enrollments(): HasMany
    {
        return $this->hasMany(UserContentEnrollment::class);
    }

    public function createdContent(): HasMany
    {
        return $this->hasMany(EducationalContent::class, 'author_id');
    }
}
