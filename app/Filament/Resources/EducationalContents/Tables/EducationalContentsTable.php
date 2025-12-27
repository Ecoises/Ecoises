<?php

namespace App\Filament\Resources\EducationalContents\Tables;

use App\Models\EducationalContent;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Illuminate\Database\Eloquent\Builder;

class EducationalContentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('thumbnail_url')
                    ->label('Imagen')
                    ->circular()
                    ->defaultImageUrl(url('/images/default-thumbnail.png')),

                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->description(fn (EducationalContent $record): string => $record->description ? strip_tags(substr($record->description, 0, 100)).'...' : ''
                    ),

                Tables\Columns\TextColumn::make('content_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EducationalContent::getTypes()[$state] ?? $state
                    )
                    ->color(fn (string $state): string => match ($state) {
                        EducationalContent::TYPE_COURSE => 'primary',
                        EducationalContent::TYPE_ARTICLE => 'success',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?string => match ($state) {
                        EducationalContent::TYPE_COURSE => 'heroicon-o-book-open',
                        EducationalContent::TYPE_ARTICLE => 'heroicon-o-document-text',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('categories.name')
                    ->label('Categoría')
                    ->badge()
                    ->separator(',')
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('difficulty_level')
                    ->label('Nivel')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EducationalContent::getDifficultyLevels()[$state] ?? $state
                    )
                    ->color(fn (string $state): string => match ($state) {
                        EducationalContent::DIFFICULTY_BEGINNER => 'success',
                        EducationalContent::DIFFICULTY_INTERMEDIATE => 'warning',
                        EducationalContent::DIFFICULTY_ADVANCED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EducationalContent::getStatuses()[$state] ?? $state
                    )
                    ->color(fn (string $state): string => match ($state) {
                        EducationalContent::STATUS_DRAFT => 'gray',
                        EducationalContent::STATUS_PENDING => 'warning',
                        EducationalContent::STATUS_REVIEWED => 'info',
                        EducationalContent::STATUS_PUBLISHED => 'success',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('is_published')
                    ->label('Publicado')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_featured')
                    ->label('Destacado')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('view_count')
                    ->label('Vistas')
                    ->sortable()
                    ->toggleable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('content_type')
                    ->label('Tipo de contenido')
                    ->options(EducationalContent::getTypes())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options(EducationalContent::getStatuses())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('difficulty_level')
                    ->label('Nivel')
                    ->options(EducationalContent::getDifficultyLevels())
                    ->multiple(),

                Tables\Filters\SelectFilter::make('categories')
                    ->label('Categoría')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->preload(),

                Tables\Filters\Filter::make('is_published')
                    ->label('Solo publicados')
                    ->query(fn (Builder $query): Builder => $query->where('is_published', true)),

                Tables\Filters\Filter::make('is_featured')
                    ->label('Solo destacados')
                    ->query(fn (Builder $query): Builder => $query->where('is_featured', true)),
            ])
            ->actions([
                
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('delete')
                        ->requiresConfirmation()
                        ->action(fn (Collection $records) => $records->each->delete()),
                    BulkAction::make('publish')
                        ->label('Publicar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update([
                            'is_published' => true,
                            'status' => EducationalContent::STATUS_PUBLISHED,
                        ])
                        )
                        ->deselectRecordsAfterCompletion(),

                  BulkAction::make('unpublish')
                        ->label('Despublicar')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_published' => false])
                        )
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
