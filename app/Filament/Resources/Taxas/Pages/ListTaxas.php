<?php

namespace App\Filament\Resources\Taxas\Pages;

use App\Filament\Resources\Taxas\TaxaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxas extends ListRecords
{
    protected static string $resource = TaxaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
