<?php

namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class Leaderboards extends TableWidget
{
    protected static ?int $sort = 5;
    protected static ?string $heading = 'Tabla de Posiciones';
   

    public function table(Table $table): Table
    {
        return $table
            ->query(
                \App\Models\User::query()
                    ->where('is_active', true) // Assuming we only want active users
                    ->doesntHave('roles')
                    ->orderByDesc('total_score')
            )
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->label('#')
                    ->rowIndex(),
                Tables\Columns\ImageColumn::make('avatar')
                    ->label('Avatar')
                    ->circular()
                    ->disk('public'),
                Tables\Columns\TextColumn::make('full_name')
                    ->label('Usuario')
                    ->searchable()
                    ->description(fn (\App\Models\User $record): string => $record->email),
                Tables\Columns\TextColumn::make('total_score')
                    ->label('Puntos')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color('success'),
            ])
            ->actions([])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No hay usuarios en el ranking')
            
            ->emptyStateIcon('heroicon-o-users');
    }
}
