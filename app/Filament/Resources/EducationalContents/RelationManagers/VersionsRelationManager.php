<?php

namespace App\Filament\Resources\EducationalContents\RelationManagers;

use App\Models\EducationalContentVersion;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Historial editorial';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version_number')
                    ->label('Versión')
                    ->formatStateUsing(fn (int $state): string => "v{$state}")
                    ->badge()
                    ->sortable(),
                TextColumn::make('event')
                    ->label('Evento')
                    ->formatStateUsing(fn (string $state): string => EducationalContentVersion::getEvents()[$state] ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        EducationalContentVersion::EVENT_PUBLISHED => 'success',
                        EducationalContentVersion::EVENT_RETURNED, EducationalContentVersion::EVENT_UNPUBLISHED => 'warning',
                        EducationalContentVersion::EVENT_REVIEWED => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('change_summary')
                    ->label('Resumen')
                    ->wrap(),
                TextColumn::make('creator.full_name')
                    ->label('Realizado por')
                    ->placeholder('Proceso automático'),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event')->label('Evento')->options(EducationalContentVersion::getEvents()),
            ])
            ->defaultSort('version_number', 'desc');
    }
}
