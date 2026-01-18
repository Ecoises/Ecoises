<?php

namespace App\Livewire;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\EducationalContent;

class TotalViewsWidget extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 1;
    
    protected function getStats(): array
    {
        $query = EducationalContent::query();

        if (auth()->user()->hasRole('educador')) {
            $query->where('author_id', auth()->id());
        }

        $totalViews = $query->sum('view_count') ?? 0;

        return [
            Stat::make('Vistas Totales', number_format($totalViews))
                ->icon('heroicon-o-eye')
                ->description($totalViews === 0 ? 'Sin interacción de usuarios' : 'Interacción de usuarios')
                ->descriptionIcon($totalViews === 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-cursor-arrow-rays')
                ->descriptionColor($totalViews === 0 ? 'danger' : null)
                ->color($totalViews === 0 ? 'gray' : 'success')
                ->chart([7, 4, 10, 15, 12, 17, 21]),
        ];
    }
}