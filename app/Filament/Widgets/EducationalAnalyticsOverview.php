<?php

namespace App\Filament\Widgets;

use App\Models\UserContentEnrollment;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EducationalAnalyticsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->hasAnyRole(['editor', 'super_admin']) ?? false)
            && (($user?->can('View:EditorialDashboard') ?? false) || ($user?->can('View:SuperAdminDashboard') ?? false));
    }

    protected function getStats(): array
    {
        $total = UserContentEnrollment::count();
        $completed = UserContentEnrollment::whereNotNull('completed_at')->count();
        $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;
        $averageRating = UserContentEnrollment::whereNotNull('user_rating')->avg('user_rating');

        return [
            Stat::make('Estudiantes activos', UserContentEnrollment::distinct('user_id')->count('user_id'))
                ->description('Personas con contenidos iniciados')
                ->icon('heroicon-o-user-group')
                ->color('info'),
            Stat::make('Finalización', "{$completionRate}%")
                ->description("{$completed} de {$total} inscripciones")
                ->icon('heroicon-o-check-badge')
                ->color($completionRate >= 60 ? 'success' : 'warning'),
            Stat::make('Valoración promedio', $averageRating !== null ? number_format((float) $averageRating, 1).' / 5' : 'Sin datos')
                ->description(UserContentEnrollment::whereNotNull('user_feedback')->count().' comentarios recibidos')
                ->icon('heroicon-o-star')
                ->color('warning'),
            Stat::make('Tiempo de aprendizaje', number_format((int) ceil(UserContentEnrollment::sum('total_time_spent') / 3600)).' h')
                ->description('Tiempo acumulado registrado')
                ->icon('heroicon-o-clock')
                ->color('gray'),
        ];
    }
}
