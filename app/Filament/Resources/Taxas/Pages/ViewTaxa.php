<?php

namespace App\Filament\Resources\Taxas\Pages;

use App\Filament\Resources\Taxas\TaxaResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTaxa extends ViewRecord
{
    protected static string $resource = TaxaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
