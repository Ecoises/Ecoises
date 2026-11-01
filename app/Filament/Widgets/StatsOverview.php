<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Taxas\TaxaResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\EducationalContent;
use App\Models\Report;
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

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $newUsers = User::where('created_at', '>=', now()->startOfMonth())->count();
        $openReports = Report::open()->count();

        return [
            Stat::make('Contenido educativo', EducationalContent::count())
                ->description(EducationalContent::published()->count().' publicados')
                ->descriptionIcon('heroicon-m-check-circle')
                ->icon('heroicon-o-academic-cap')
                ->color('success')
                ->url(EducationalContentResource::getUrl('index')),

            Stat::make('Usuarios registrados', User::count())
                ->description($newUsers > 0 ? "{$newUsers} nuevos este mes" : 'Sin registros este mes')
                ->descriptionIcon($newUsers > 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-minus')
                ->icon('heroicon-o-user-group')
                ->color($newUsers > 0 ? 'success' : 'gray')
                ->url(UserResource::getUrl('index')),

            Stat::make('Especies en el catálogo', number_format(Taxa::count()))
                ->description(number_format(Taxa::where('is_endemic', true)->count()).' endémicas')
                ->descriptionIcon('heroicon-m-sparkles')
                ->icon('heroicon-o-bug-ant')
                ->color('info')
                ->url(TaxaResource::getUrl('index')),

            Stat::make('Reportes por atender', $openReports)
                ->description($openReports > 0 ? 'Requieren moderación' : 'Todo al día')
                ->descriptionIcon($openReports > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check')
                ->icon('heroicon-o-flag')
                ->color($openReports > 0 ? 'danger' : 'success')
                ->url(ReportResource::getUrl('index')),
        ];
    }
}
