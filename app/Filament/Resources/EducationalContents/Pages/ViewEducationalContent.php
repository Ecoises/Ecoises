<?php

namespace App\Filament\Resources\EducationalContents\Pages;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewEducationalContent extends ViewRecord
{
    protected static string $resource = EducationalContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
            ->icon('heroicon-o-pencil'),
        ];
    }

    public function getTitle(): string|Htmlable
    {
        /** @var Post */
        $record = $this->getRecord();

        return $record->title;
    }
}
