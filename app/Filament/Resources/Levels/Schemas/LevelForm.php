<?php

namespace App\Filament\Resources\Levels\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class LevelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                TextInput::make('min_points')
                    ->label('Puntos mínimos')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->unique(ignoreRecord: true),
                TextInput::make('icon')->label('Ícono (emoji o nombre)')->maxLength(255),
                ColorPicker::make('color')->label('Color'),
                TextInput::make('description')->label('Descripción')->maxLength(255)->columnSpanFull(),
                Toggle::make('is_active')->label('Activo')->default(true),
            ])
            ->columns(2);
    }
}
