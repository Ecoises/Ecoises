<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string|Htmlable
    {
        $user = auth()->user();

        return match (true) {
            $user?->hasRole('super_admin') => 'Resumen general de Ecoises',
            $user?->hasRole('editor') => 'Panel editorial',
            $user?->hasRole('educador') => 'Mi espacio educativo',
            $user?->hasRole('moderador') => 'Panel de moderación',
            default => 'Panel administrativo',
        };
    }

    public function getColumns(): int|array
    {
        return [
            'md' => 2,
            'xl' => 3,
        ];
    }
}
