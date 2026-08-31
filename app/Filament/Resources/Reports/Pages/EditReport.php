<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('takeCase')
                ->label('Tomar caso')
                ->icon('heroicon-o-hand-raised')
                ->visible(fn (): bool => $this->getRecord()->assigned_to === null)
                ->action(function (): void {
                    $this->getRecord()->update([
                        'assigned_to' => auth()->id(),
                        'status' => Report::STATUS_IN_REVIEW,
                    ]);
                    $this->fillForm();
                    Notification::make()->title('Caso asignado')->success()->send();
                }),
            Action::make('resolve')
                ->label('Marcar resuelto')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => ! in_array($this->getRecord()->status, [Report::STATUS_RESOLVED, Report::STATUS_DISMISSED], true))
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->getRecord()->update(['status' => Report::STATUS_RESOLVED]);
                    $this->fillForm();
                    Notification::make()->title('Caso resuelto')->success()->send();
                }),
        ];
    }
}
