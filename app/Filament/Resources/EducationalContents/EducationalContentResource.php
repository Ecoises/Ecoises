<?php

namespace App\Filament\Resources\EducationalContents;

use App\Filament\Resources\EducationalContents\Pages\CreateEducationalContent;
use App\Filament\Resources\EducationalContents\Pages\EditEducationalContent;
use App\Filament\Resources\EducationalContents\Pages\ListEducationalContents;
use App\Filament\Resources\EducationalContents\Pages\ViewEducationalContent;
use App\Filament\Resources\EducationalContents\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\EducationalContents\Schemas\EducationalContentForm;
use App\Filament\Resources\EducationalContents\Schemas\EducationalContentInfolist;
use App\Filament\Resources\EducationalContents\Tables\EducationalContentsTable;
use App\Models\EducationalContent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EducationalContentResource extends Resource
{
    protected static ?string $model = EducationalContent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Gestión de Contenido';

    protected static ?string $navigationLabel = 'Contenido Educativo';

    protected static ?string $modelLabel = 'Contenido Educativo';

    protected static ?string $pluralModelLabel = 'Contenido Educativo';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }

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
            VersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEducationalContents::route('/'),
            'create' => CreateEducationalContent::route('/create'),
            'view' => ViewEducationalContent::route('/{record}'),
            'edit' => EditEducationalContent::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery();

        if (auth()->user()->hasRole('educador')) {
            $query->where('author_id', auth()->id());
        }

        return $query;
    }
}
