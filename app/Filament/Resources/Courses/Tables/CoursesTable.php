<?php

namespace App\Filament\Resources\Courses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;


class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('difficulty_level'),
                TextColumn::make('category.name')
                    ->label('Categoría')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('tags')
                    ->label('Etiquetas')
                    ->badge()
                    ->separator(','),
                TextColumn::make('estimated_duration')
                    ->label('Duración')
                    ->sortable()
                    ->formatStateUsing(function ($state) {
                        if (!$state) return '00:00:00';
                        $hours = floor($state / 3600);
                        $minutes = floor(($state % 3600) / 60);
                        $seconds = $state % 60;
                        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                    }),
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
                TextColumn::make('completion_points')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('achievement.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('author.full_name')
                    ->sortable(),
                TextColumn::make('enrollment_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('completion_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rating_average')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rating_count')
                    ->numeric()
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
