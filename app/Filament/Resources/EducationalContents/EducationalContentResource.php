<?php

namespace App\Filament\Resources\EducationalContents;

use App\Filament\Resources\EducationalContents\Pages\CreateEducationalContent;
use App\Filament\Resources\EducationalContents\Pages\EditEducationalContent;
use App\Filament\Resources\EducationalContents\Pages\ListEducationalContents;
use App\Filament\Resources\EducationalContents\Schemas\EducationalContentForm;
use App\Filament\Resources\EducationalContents\Schemas\EducationalContentInfolist;
use App\Filament\Resources\EducationalContents\Tables\EducationalContentsTable;
use App\Models\EducationalContent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EducationalContentResource extends Resource
{
    protected static ?string $model = EducationalContent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return EducationalContentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EducationalContentsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EducationalContentInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            // No relations needed here as we embed them in the Wizard or Repeater
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEducationalContents::route('/'),
            'create' => CreateEducationalContent::route('/create'),
            'edit' => EditEducationalContent::route('/{record}/edit'),
        ];
    }
}
