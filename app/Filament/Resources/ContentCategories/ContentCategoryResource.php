<?php

namespace App\Filament\Resources\ContentCategories;

use App\Filament\Resources\ContentCategories\Pages;
use App\Filament\Resources\ContentCategories\RelationManagers;
use App\Models\ContentCategory;
use BackedEnum;
use UnitEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class ContentCategoryResource extends Resource
{
    protected static ?string $model = ContentCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static string | UnitEnum | null $navigationGroup = 'Gestión de Contenido';

    protected static ?string $navigationLabel = 'Áreas Temáticas';

    protected static ?string $modelLabel = 'Área Temática';

    protected static ?string $pluralModelLabel = 'Áreas Temáticas';

    protected static ?string $recordTitleAttribute = 'name';

    

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(debounce: 500)
                    ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug($state))),

                TextInput::make('slug')
                    ->required()
                    ->unique('content_categories', 'slug', ignoreRecord: true)
                    ->dehydrated()
                    ->readOnly(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->badge()
                    ->searchable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->searchable(),
                TextColumn::make('updated_at')
                    ->label('Actualizado')
                    ->dateTime('d/m/Y H:i')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ContentRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentCategories::route('/'),
            'create' => Pages\CreateContentCategory::route('/create'),
            'edit' => Pages\EditContentCategory::route('/{record}/edit'),
        ];
    }
}
