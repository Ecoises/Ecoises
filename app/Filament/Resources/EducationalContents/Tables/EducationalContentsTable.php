<?php

namespace App\Filament\Resources\EducationalContents\Tables;

use App\Models\EducationalContent;
use App\Services\EducationalContentPublicationService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class EducationalContentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumbnail_url')
                    ->disk('public')
                    ->label('Portada')
                    ->circular(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(50)
                    ->description(fn (EducationalContent $record): string => $record->description ? strip_tags(substr($record->description, 0, 50)).'...' : ''
                    ),

                Tables\Columns\TextColumn::make('content_type')
                    ->label('Tipo')
                    ->badge()
                    ->placeholder('Sin definir')
                    ->formatStateUsing(fn (?string $state): string => EducationalContent::getTypes()[$state] ?? 'Sin definir'
                    )
                    ->color(fn (?string $state): string => match ($state) {
                        EducationalContent::TYPE_COURSE => 'success',
                        EducationalContent::TYPE_ARTICLE => 'info',
                        EducationalContent::TYPE_RESOURCE => 'warning',
                        default => 'gray',
                    })
                    ->icon(fn (?string $state): ?string => match ($state) {
                        EducationalContent::TYPE_COURSE => 'heroicon-o-book-open',
                        EducationalContent::TYPE_ARTICLE => 'heroicon-o-document-text',
                        EducationalContent::TYPE_RESOURCE => 'heroicon-o-photo',
                        default => null,
                    }),

                Tables\Columns\TextColumn::make('categories.name')
                    ->label('Categoría')
                    ->badge()
                    ->listWithLineBreaks()
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
                    ->sortable()
                    ->icon(fn (string $state): ?string => match ($state) {
                        EducationalContent::STATUS_DRAFT => 'heroicon-o-pencil-square',
                        EducationalContent::STATUS_PENDING => 'heroicon-o-clock',
                        EducationalContent::STATUS_REVIEWED => 'heroicon-o-document-text',
                        EducationalContent::STATUS_PUBLISHED => 'heroicon-o-check-badge',
                        default => null,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        EducationalContent::STATUS_DRAFT => 'warning',
                        EducationalContent::STATUS_PENDING => 'danger',
                        EducationalContent::STATUS_REVIEWED => 'info',
                        EducationalContent::STATUS_PUBLISHED => 'success',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('author.full_name')
                    ->label('Autor')
                    ->searchable()
                    ->sortable(),

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
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->successNotification(
                        Notification::make()
                            ->success()
                            ->title('Contenido eliminado')
                            ->body('El contenido ha sido eliminado correctamente.'),
                    ),
            ])
            ->bulkActions([

                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title('Contenido eliminado')
                                ->body('El contenido seleccionado ha sido eliminado correctamente.'),
                        ),
                    BulkAction::make('publish')
                        ->label('Publicar')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()?->can('Publish:EducationalContent') ?? false)
                        ->action(function (Collection $records): void {
                            $failed = 0;

                            foreach ($records as $record) {
                                try {
                                    if ($record->status !== EducationalContent::STATUS_REVIEWED) {
                                        $failed++;

                                        continue;
                                    }

                                    app(EducationalContentPublicationService::class)->publish($record);
                                } catch (ValidationException) {
                                    $failed++;
                                }
                            }

                            Notification::make()
                                ->title($failed === 0 ? 'Contenidos publicados' : 'Publicación parcial')
                                ->body($failed === 0
                                    ? 'Todos los contenidos seleccionados fueron publicados.'
                                    : "{$failed} contenido(s) necesitan información antes de publicarse.")
                                ->color($failed === 0 ? 'success' : 'warning')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('unpublish')
                        ->label('Despublicar')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => auth()->user()?->can('Publish:EducationalContent') ?? false)
                        ->action(fn (Collection $records) => $records->each(
                            fn (EducationalContent $record) => app(EducationalContentPublicationService::class)->unpublish($record)
                        ))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Aún no hay contenido')
            ->emptyStateDescription('Aún no se han creado contenidos educativos.')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateActions([
                Action::make('create')
                    ->label('Crear contenido')
                    ->icon('heroicon-o-plus')
                    ->url(route('filament.admin.resources.educational-contents.create'))
                    ->button(),
            ]);

    }
}
