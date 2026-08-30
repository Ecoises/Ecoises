<?php

namespace App\Filament\Resources\EducationalContents\Pages;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use App\Services\EducationalDraftService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateEducationalContent extends CreateRecord
{
    protected static string $resource = EducationalContentResource::class;

    public function mount(): void
    {
        $this->authorizeAccess();

        $draft = app(EducationalDraftService::class)->create(auth()->user());

        $this->redirect(
            static::getResource()::getUrl('edit', ['record' => $draft]),
            navigate: true,
        );
    }

    protected function getFormActions(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancelar')
                ->outlined()
                ->color('gray')
                ->url(fn (): string => static::getResource()::getUrl('index'))
                ->icon('heroicon-o-x-mark'),
        ];
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('¡Guardado exitoso!')
            ->body('Contenido educativo creado correctamente')
            ->icon('heroicon-o-check-badge')
            ->duration(5000)
            ->send();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
