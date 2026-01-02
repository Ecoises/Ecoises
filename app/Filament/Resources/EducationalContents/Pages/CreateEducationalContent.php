<?php

namespace App\Filament\Resources\EducationalContents\Pages;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use App\Models\ArticleDetails;
use App\Models\CourseDetails;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;

class CreateEducationalContent extends CreateRecord
{
    protected static string $resource = EducationalContentResource::class;

    protected function getFormActions(): array
    {
        return [];
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

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            // Separa los datos de los detalles
            $courseDetailsData = $data['course_details'] ?? [];
            $articleDetailsData = $data['article_details'] ?? [];

            // Limpia los datos del modelo principal
            unset($data['course_details']);
            unset($data['article_details']);

            // Crea el registro principal
            $record = static::getModel()::create($data);

            // Crea los detalles según el tipo
            if ($data['content_type'] === 'course') {
                $record->courseDetails()->create($courseDetailsData);
            } elseif ($data['content_type'] === 'article') {
                $record->articleDetails()->create($articleDetailsData);
            }

            return $record;
        });
    }
}
