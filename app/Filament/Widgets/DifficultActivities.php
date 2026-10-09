<?php

namespace App\Filament\Widgets;

use App\Models\Activity;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class DifficultActivities extends TableWidget
{
    protected static ?int $sort = 4;

    protected static ?string $heading = 'Actividades que necesitan revisión';

    public static function canView(): bool
    {
        $user = auth()->user();

        return ($user?->hasAnyRole(['editor', 'super_admin']) ?? false)
            && (($user?->can('View:EditorialDashboard') ?? false) || ($user?->can('View:SuperAdminDashboard') ?? false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Activity::query()
                    ->join('user_activity_attempts', 'activities.id', '=', 'user_activity_attempts.activity_id')
                    ->select('activities.*')
                    ->selectRaw('COUNT(DISTINCT user_activity_attempts.user_id) as learners_count')
                    ->selectRaw('ROUND(100 * COUNT(DISTINCT CASE WHEN user_activity_attempts.is_correct = 1 THEN user_activity_attempts.user_id END) / COUNT(DISTINCT user_activity_attempts.user_id), 1) as success_rate')
                    ->groupBy('activities.id')
                    ->havingRaw('COUNT(DISTINCT user_activity_attempts.user_id) >= 1')
                    ->orderBy('success_rate')
            )
            ->columns([
                TextColumn::make('title')->label('Actividad')->searchable()->limit(55),
                TextColumn::make('activity_type')->label('Tipo')->badge(),
                TextColumn::make('learners_count')->label('Estudiantes')->numeric()->sortable(),
                TextColumn::make('success_rate')
                    ->label('Aprobación')
                    ->suffix('%')
                    ->badge()
                    ->color(fn ($state): string => (float) $state < 40 ? 'danger' : ((float) $state < 70 ? 'warning' : 'success'))
                    ->sortable(),
            ])
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Aún no hay intentos suficientes')
            ->emptyStateDescription('Las dificultades aparecerán cuando los estudiantes respondan actividades.');
    }
}
