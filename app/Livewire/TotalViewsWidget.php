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
        $totalViews = EducationalContent::sum('view_count') ?? 0;

        return [
            // 2. Ahora sí podemos usar la variable $totalViews
            Stat::make('Vistas Totales', number_format($totalViews))
                ->icon('heroicon-o-eye')
                ->description('Interacción de usuarios')
                ->descriptionIcon('heroicon-m-cursor-arrow-rays')
                ->color('success')
                ->chart([7, 4, 10, 15, 12, 17, 21]),
        ];
    }
}
