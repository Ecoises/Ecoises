<?php

namespace App\Livewire;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\EducationalContent;
use Carbon\Carbon;

class TotalContentsWidget extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 1;
    
    protected function getStats(): array
    {
        $total = EducationalContent::count() ?? 0;
    
        $ultimoMes = EducationalContent::where('created_at', '>=', Carbon::now()->subMonth())->count();

        return [
            Stat::make('Total de Contenidos', $total)
            ->icon('heroicon-o-document-text')
            ->color('success')
            ->description($ultimoMes . ' creados este mes')
            ->descriptionIcon('heroicon-m-arrow-trending-up'),
            
        ];
    }
}
