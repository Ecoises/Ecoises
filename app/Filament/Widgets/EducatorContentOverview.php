<?php

namespace App\Filament\Widgets;

use App\Models\EducationalContent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EducatorContentOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return (auth()->user()?->hasRole('educador') ?? false)
            && (auth()->user()?->can('View:EducatorDashboard') ?? false);
    }

    protected function getStats(): array
    {
        $query = EducationalContent::where('author_id', auth()->id());

        return [
            Stat::make('Mis borradores', (clone $query)->where('status', EducationalContent::STATUS_DRAFT)->count())
                ->icon('heroicon-o-pencil-square')
                ->color('warning'),
            Stat::make('En revisión', (clone $query)->whereIn('status', [EducationalContent::STATUS_PENDING, EducationalContent::STATUS_REVIEWED])->count())
                ->icon('heroicon-o-clock')
                ->color('info'),
            Stat::make('Publicados', (clone $query)->published()->count())
                ->icon('heroicon-o-check-badge')
                ->color('success'),
            Stat::make('Vistas de mis contenidos', number_format((int) (clone $query)->sum('view_count')))
                ->icon('heroicon-o-eye')
                ->color('gray'),
        ];
    }
}
