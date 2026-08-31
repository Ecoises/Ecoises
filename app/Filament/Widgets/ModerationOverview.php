<?php

namespace App\Filament\Widgets;

use App\Models\Report;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ModerationOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return (auth()->user()?->hasRole('moderador') ?? false)
            && (auth()->user()?->can('View:ModerationDashboard') ?? false);
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Reportes por atender', Report::open()->count())
                ->icon('heroicon-o-flag')
                ->color(Report::open()->exists() ? 'danger' : 'success')
                ->description('Pendientes o en revisión'),
            Stat::make('Observaciones reportadas', Report::where('type', Report::TYPE_OBSERVATION)->open()->count())
                ->icon('heroicon-o-eye-slash')
                ->color('warning'),
            Stat::make('Feedback educativo', Report::where('type', Report::TYPE_CONTENT_FEEDBACK)->open()->count())
                ->icon('heroicon-o-academic-cap')
                ->color('info'),
        ];
    }
}
