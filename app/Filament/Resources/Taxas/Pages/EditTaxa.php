<?php

namespace App\Filament\Resources\Taxas\Pages;

use App\Filament\Resources\Taxas\TaxaResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTaxa extends EditRecord
{
    protected static string $resource = TaxaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
