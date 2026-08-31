<?php

namespace App\Filament\Widgets;

use App\Models\Announcement;
use App\Models\EducationalContent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EditorialWorkflowOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return (auth()->user()?->hasRole('editor') ?? false)
            && (auth()->user()?->can('View:EditorialDashboard') ?? false);
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Pendientes de revisión', EducationalContent::where('status', EducationalContent::STATUS_PENDING)->count())
                ->icon('heroicon-o-clock')
                ->color('warning')
                ->description('Esperan revisión editorial'),
            Stat::make('Listos para publicar', EducationalContent::where('status', EducationalContent::STATUS_REVIEWED)->count())
                ->icon('heroicon-o-clipboard-document-check')
                ->color('info')
                ->description('Revisión aprobada'),
            Stat::make('Anuncios activos', Announcement::visible()->count())
                ->icon('heroicon-o-megaphone')
                ->color('success')
                ->description('Visibles en la aplicación'),
        ];
    }
}
