<?php

namespace App\Filament\Widgets;

use App\Models\EducationalContent;
use App\Models\Taxa;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->can('View:SuperAdminDashboard') ?? false;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Contenido Educativo', EducationalContent::count())
                ->icon('heroicon-o-document-text')
                ->color('success')
                ->description('Contenido Educativo')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->descriptionColor('secondary'),

            Stat::make('Usuarios Registrados', User::count())
                ->icon('heroicon-o-user-group')
                ->color('warning')
                ->description('Usuarios Registrados')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->descriptionColor('secondary'),

            Stat::make('Taxones', Taxa::count())
                ->icon('heroicon-o-bug-ant')
                ->description('Taxones Guardados')
                ->descriptionIcon('heroicon-o-arrow-trending-up')
                ->descriptionColor('secondary'),

        ];
    }
}
