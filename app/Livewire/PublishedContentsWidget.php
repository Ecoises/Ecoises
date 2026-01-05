<?php

namespace App\Livewire;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\EducationalContent;
use Carbon\Carbon;

class PublishedContentsWidget extends StatsOverviewWidget
{
    protected int | string | array $columnSpan = 1;

    protected function getStats(): array
{
    $publicados = EducationalContent::where('is_published', true)->count() ?? 0;

    $publicadosMes = EducationalContent::where('is_published', true)
        ->where('created_at', '>=', Carbon::now()->subMonth())
        ->count();

    return [
        Stat::make('Publicados', $publicados)
            ->icon('heroicon-o-check-circle')
            ->description($publicadosMes . ' nuevos publicados')
            ->descriptionIcon($publicadosMes === 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
            ->descriptionColor($publicadosMes === 0 ? 'danger' : null)
            ->color('success'),
    ];
}
}
