<?php

namespace App\Filament\Resources\Courses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;


class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_url')
                    ->disk('public')
                    ->label('Portada')
                    ->circular(),
                TextColumn::make('title')
                    ->searchable(),
                // TextColumn::make('difficulty_level'),
                TextColumn::make('category.name')
                    ->label('Área Temática')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('author.full_name')
                    ->label('Autor')
                    ->searchable()
                    ->sortable(),    
                TextColumn::make('status')
                ->label('Estado')
                ->badge()
                ->icon(fn (string $state): string => match ($state) {
                    'draft' => 'heroicon-o-pencil-square',
                    'pending' => 'heroicon-o-clock',
                    'reviewed' => 'heroicon-o-eye',
                    'published' => 'heroicon-o-check-circle',
                    default => 'heroicon-o-question-mark-circle',
                })
                ->color(fn (string $state): string => match ($state) {
                    'draft' => 'warning',
                    'pending' => 'danger',
                    'reviewed' => 'info',
                    'published' => 'success',
                    default => 'warning',
                })
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'draft' => 'Borrador',
                    'pending' => 'Pendiente',
                    'reviewed' => 'Revisado',
                    'published' => 'Publicado',
                    default => $state,
                })
                ->sortable()
                ->searchable(),
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
                ViewAction::make()
                ->modal(false),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
