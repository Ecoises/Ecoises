<?php

namespace App\Filament\Resources\EducationalContents\Pages;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditEducationalContent extends EditRecord
{
    protected static string $resource = EducationalContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
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
                    ['educational_content_id' => $record->id],
                    $courseDetailsData
                );
            } elseif ($record->content_type === 'article') {
                $record->articleDetails()->updateOrCreate(
                    ['educational_content_id' => $record->id],
                    $articleDetailsData
                );
            }

            return $record;
        });
    }
}
