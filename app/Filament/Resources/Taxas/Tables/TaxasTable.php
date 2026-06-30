<?php

namespace App\Filament\Resources\Taxas\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaxasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('scientific_name')
                    ->searchable(),
                TextColumn::make('common_name')
                    ->searchable(),
                TextColumn::make('kingdom')
                    ->searchable(),
                TextColumn::make('phylum')
                    ->searchable(),
                TextColumn::make('class')
                    ->searchable(),
                TextColumn::make('order_name')
                    ->searchable(),
                TextColumn::make('family')
                    ->searchable(),
                TextColumn::make('genus')
                    ->searchable(),
                TextColumn::make('species')
                    ->searchable(),
                TextColumn::make('conservation_status')
                    ->badge(),
                IconColumn::make('is_native')
                    ->boolean(),
                IconColumn::make('is_endemic')
                    ->boolean(),
                TextColumn::make('taxon_author')
                    ->searchable(),
                TextColumn::make('inventory_author')
                    ->searchable(),
                TextColumn::make('local_records_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('observation_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('identification_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_observed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
