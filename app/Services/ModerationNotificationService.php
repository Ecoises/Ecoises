<?php

namespace App\Services;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Schema;

class ModerationNotificationService
{
    public function notifyNewReport(Report $report): void
    {
        if (! $this->permissionTablesExist() || ! Schema::hasTable('notifications')) {
            return;
        }

        $recipients = User::query()
            ->role(['moderador', 'super_admin'])
            ->where('is_active', true)
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('Nuevo caso de moderación')
            ->body($this->notificationBody($report))
            ->icon('heroicon-o-flag')
            ->color($report->priority === Report::PRIORITY_URGENT ? 'danger' : 'warning')
            ->actions([
                Action::make('view')
                    ->label('Revisar caso')
                    ->url(ReportResource::getUrl('edit', ['record' => $report])),
            ])
            ->sendToDatabase($recipients);
    }

    private function permissionTablesExist(): bool
    {
        return Schema::hasTable('roles')
            && Schema::hasTable('model_has_roles');
    }

    private function notificationBody(Report $report): string
    {
        $type = Report::getTypes()[$report->type] ?? 'Reporte';
        $subject = $report->subject ?: ($report->observation_id ? "Observación #{$report->observation_id}" : null);

        return $subject ? "{$type}: {$subject}" : $type;
    }
}
