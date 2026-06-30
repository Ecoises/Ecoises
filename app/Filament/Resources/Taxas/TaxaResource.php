<?php

namespace App\Filament\Resources\Taxas;

use App\Filament\Resources\Taxas\Pages\CreateTaxa;
use App\Filament\Resources\Taxas\Pages\EditTaxa;
use App\Filament\Resources\Taxas\Pages\ListTaxas;
use App\Filament\Resources\Taxas\Pages\ViewTaxa;
use App\Filament\Resources\Taxas\Schemas\TaxaForm;
use App\Filament\Resources\Taxas\Schemas\TaxaInfolist;
use App\Filament\Resources\Taxas\Tables\TaxasTable;
use App\Models\Taxa;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TaxaResource extends Resource
{
    protected static ?string $model = Taxa::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'scientific_name';

    public static function form(Schema $schema): Schema
    {
        return TaxaForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TaxaInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TaxasTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTaxas::route('/'),
            'create' => CreateTaxa::route('/create'),
            'view' => ViewTaxa::route('/{record}'),
            'edit' => EditTaxa::route('/{record}/edit'),
        ];
    }
}
