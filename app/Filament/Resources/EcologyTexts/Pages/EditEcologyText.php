<?php

namespace App\Filament\Resources\EcologyTexts\Pages;

use App\Filament\Resources\EcologyTexts\EcologyTextResource;
use App\Models\SpeciesEcologyText;
use Filament\Resources\Pages\EditRecord;

class EditEcologyText extends EditRecord
{
    protected static string $resource = EcologyTextResource::class;

    /** Quien guarda un texto como revisado queda registrado como revisor. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['status'] ?? null) === SpeciesEcologyText::STATUS_REVIEWED) {
            $data['reviewed_by'] = auth()->id();
            $data['reviewed_at'] = now();
        }

        return $data;
    }
}
