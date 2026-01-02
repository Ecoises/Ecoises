<?php

namespace App\Filament\Resources\EducationalContents\Pages;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class EditEducationalContent extends EditRecord
{
    protected static string $resource = EducationalContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
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
        return $this->getResource()::getUrl('index');
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
