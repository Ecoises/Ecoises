<?php

namespace App\Filament\Resources\EducationalContents\Pages;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use App\Models\EducationalContent;
use App\Services\EducationalDraftService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EditEducationalContent extends EditRecord
{
    protected static string $resource = EducationalContentResource::class;

    public bool $isAutosaving = false;

    public ?string $lastAutosavedAt = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label('Cancelar')
                ->outlined()
                ->color('gray')
                ->url(fn (): string => static::getResource()::getUrl('index'))
                ->icon('heroicon-o-x-mark'),

            DeleteAction::make()
                ->icon('heroicon-o-trash'),
        ];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Post */
        $record = $this->getRecord();

        return $record->title;
    }

    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('¡Actualizado!')
            ->body('Contenido educativo actualizado correctamente')
            ->icon('heroicon-o-check-badge')
            ->duration(5000)
            ->send();
    }

    // Redirecciona al listado después de guardar
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }

    public function autosaveDraft(): void
    {
        if ($this->isAutosaving
            || ! $this->getRecord()->exists
            || $this->getRecord()->status !== EducationalContent::STATUS_DRAFT) {
            return;
        }

        $this->isAutosaving = true;

        try {
            $state = $this->form->getRawState();
            $containsNewRelationshipItems = $this->containsNewRelationshipItems($state);
            $record = app(EducationalDraftService::class)->autosave($this->getRecord(), $state);

            // Filament se encarga de categorías, lecciones y actividades. Los
            // mutadores del formulario omiten filas todavía completamente vacías.
            try {
                $this->form->model($record)->saveRelationships();

                // Una fila recién creada cambia de una clave temporal UUID a
                // record-{id}. Rehidratar solo en ese caso evita duplicados en
                // autoguardados posteriores sin interrumpir la escritura normal.
                if ($containsNewRelationshipItems) {
                    $this->fillForm();
                }
            } catch (Throwable $relationshipError) {
                // El texto principal ya quedó protegido. Una actividad a medio
                // construir no debe romper ni ensuciar la experiencia del editor.
                Log::debug('Relación educativa incompleta durante autoguardado', [
                    'content_id' => $record->id,
                    'message' => $relationshipError->getMessage(),
                ]);
            }

            $this->lastAutosavedAt = now()->format('H:i:s');
            $this->dispatch('educational-draft-autosaved', savedAt: $this->lastAutosavedAt);
        } catch (Throwable $error) {
            Log::warning('No se pudo autoguardar el contenido educativo', [
                'content_id' => $this->getRecord()->id,
                'message' => $error->getMessage(),
            ]);

            $this->dispatch('educational-draft-autosave-failed');
        } finally {
            $this->isAutosaving = false;
        }
    }

    protected function containsNewRelationshipItems(array $state): bool
    {
        $hasTemporaryItem = function (mixed $items) use (&$hasTemporaryItem): bool {
            if (! is_array($items)) {
                return false;
            }

            foreach ($items as $key => $item) {
                if (is_string($key)
                    && ! str_starts_with($key, 'record-')
                    && is_array($item)
                    && (filled($item['title'] ?? null) || filled(strip_tags($item['content_text'] ?? '')))) {
                    return true;
                }

                if (is_array($item) && $hasTemporaryItem($item['activities'] ?? null)) {
                    return true;
                }
            }

            return false;
        };

        return $hasTemporaryItem($state['lessons'] ?? null)
            || $hasTemporaryItem($state['activities'] ?? null);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record->content_type === 'course' && $record->courseDetails) {
            $data['course_details'] = $record->courseDetails->toArray();
        } elseif ($record->content_type === 'article' && $record->articleDetails) {
            $data['article_details'] = $record->articleDetails->toArray();
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $courseDetailsData = $data['course_details'] ?? [];
            $articleDetailsData = $data['article_details'] ?? [];

            // El audio pertenece al trabajo asíncrono. Un formulario que quedó
            // abierto no debe sobrescribir con valores antiguos su resultado.
            unset($articleDetailsData['audio_url'], $articleDetailsData['audio_timestamps']);

            unset($data['course_details']);
            unset($data['article_details']);

            $record->update($data);

            if ($record->content_type === 'course') {
                $record->courseDetails()->updateOrCreate(
                    ['id' => $record->id],
                    $courseDetailsData
                );
            } elseif ($record->content_type === 'article') {
                $record->articleDetails()->updateOrCreate(
                    ['id' => $record->id],
                    $articleDetailsData
                );
            }

            return $record;
        });
    }
}
