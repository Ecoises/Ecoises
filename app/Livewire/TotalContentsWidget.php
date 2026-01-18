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
        $query = EducationalContent::query();

        if (auth()->user()->hasRole('educador')) {
            $query->where('author_id', auth()->id());
        }

        $total = $query->count() ?? 0;
    
        $queryMes = EducationalContent::where('created_at', '>=', Carbon::now()->subMonth());

        if (auth()->user()->hasRole('educador')) {
            $queryMes->where('author_id', auth()->id());
        }

        $ultimoMes = $queryMes->count();
        
        return [
            Stat::make('Total de Contenidos', $total)
                ->icon('heroicon-o-document-text')
                ->color('success')
                ->description($ultimoMes . ' creados este mes')
                ->descriptionIcon($ultimoMes === 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->descriptionColor($ultimoMes === 0 ? 'danger' : null),
        ];
    }
}