<?php

namespace App\Filament\Resources\Levels\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LevelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('icon')->label(''),
                TextColumn::make('name')->label('Nivel')->searchable(),
                TextColumn::make('min_points')->label('Desde (puntos)')->numeric()->sortable(),
                ColorColumn::make('color')->label('Color'),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->defaultSort('min_points')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
