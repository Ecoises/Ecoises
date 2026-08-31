<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use App\Services\AnnouncementPublicationService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class EditAnnouncement extends EditRecord
{
    protected static string $resource = AnnouncementResource::class;

    public bool $isAutosaving = false;

    public ?string $lastAutosavedAt = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publicar')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->status === Announcement::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalDescription('Guardaremos el formulario y validaremos el anuncio antes de hacerlo visible.')
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

                    try {
                        app(AnnouncementPublicationService::class)->publish($this->getRecord()->fresh());
                        $this->fillForm();

                        Notification::make()
                            ->title('Anuncio publicado')
                            ->body('Se mostrará cuando comience su periodo de vigencia.')
                            ->success()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('El anuncio todavía no está listo')
                            ->body(collect($exception->errors())->flatten()->implode("\n"))
                            ->warning()
                            ->persistent()
                            ->send();
                    }
                }),

            Action::make('unpublish')
                ->label('Despublicar')
                ->icon('heroicon-o-eye-slash')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->status === Announcement::STATUS_PUBLISHED)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(AnnouncementPublicationService::class)->unpublish($this->getRecord());
                    $this->fillForm();

                    Notification::make()->title('Anuncio retirado')->success()->send();
                }),

            DeleteAction::make(),
        ];
    }

    public function autosaveDraft(): void
    {
        $record = $this->getRecord();

        if ($this->isAutosaving || $record->status !== Announcement::STATUS_DRAFT) {
            return;
        }

        $this->isAutosaving = true;

        try {
            $data = Arr::only($this->form->getRawState(), [
                'title',
                'slug',
                'summary',
                'body',
                'cover_image',
                'cta_label',
                'cta_url',
                'audience',
                'is_pinned',
                'starts_at',
                'ends_at',
            ]);

            if (blank($data['title'] ?? null)) {
                unset($data['title']);
            }

            if (blank($data['slug'] ?? null)
                || Announcement::query()->where('slug', $data['slug'] ?? '')->whereKeyNot($record->getKey())->exists()) {
                unset($data['slug']);
            }

            if (($data['cover_image'] ?? null) !== null && ! is_string($data['cover_image'])) {
                unset($data['cover_image']);
            }

            $record->fill($data)->save();
            $this->lastAutosavedAt = now()->format('H:i:s');
        } catch (Throwable $error) {
            Log::warning('No se pudo autoguardar el anuncio', [
                'announcement_id' => $record->id,
                'message' => $error->getMessage(),
            ]);
        } finally {
            $this->isAutosaving = false;
        }
    }
}
