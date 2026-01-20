<?php

namespace App\Filament\Resources\ContentCategories\RelationManagers;

use App\Models\EducationalContent;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;

class ContentRelationManager extends RelationManager
{
    protected static string $relationship = 'content';

    protected static ?string $title = 'Contenidos Educativos';

    protected static ?string $recordTitleAttribute = 'title';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('title')
                    ->label('Título')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Título')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('content_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => EducationalContent::getTypes()[$state] ?? $state
                    )
                    ->color(fn (string $state): string => match ($state) {
                        EducationalContent::TYPE_COURSE => 'success',
                        EducationalContent::TYPE_ARTICLE => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): ?string => match ($state) {
                        EducationalContent::TYPE_COURSE => 'heroicon-o-book-open',
                        EducationalContent::TYPE_ARTICLE => 'heroicon-o-document-text',
                        default => null,
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
                    ->boolean(),

                Tables\Columns\TextColumn::make('view_count')
                    ->label('Vistas')
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creado')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('content_type')
                    ->label('Tipo de Contenido')
                    ->options([
                        'course' => 'Curso',
                        'article' => 'Artículo',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'draft' => 'Borrador',
                        'reviewed' => 'Revisado',
                        'published' => 'Publicado',
                    ]),

                Tables\Filters\TernaryFilter::make('is_published')
                    ->label('Publicado')
                    ->placeholder('Todos')
                    ->trueLabel('Solo publicados')
                    ->falseLabel('Solo no publicados'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Asociar contenido')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['title', 'slug'])
                    ->recordTitle(fn (EducationalContent $record): string => "{$record->title} ({$record->content_type})"),
            ])
            ->actions([
                ViewAction::make()
                    ->url(fn (EducationalContent $record): string => 
                        route('filament.admin.resources.educational-contents.edit', ['record' => $record->id])
                    ),
                DetachAction::make()
                    ->label('Desasociar'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->label('Desasociar seleccionados'),
                ]),
            ])
            ->emptyStateHeading('No hay contenidos asociados')
            ->emptyStateDescription('Asocia contenidos educativos a esta categoría usando el botón "Asociar contenido".')
            ->emptyStateIcon('heroicon-o-document-text')
            ->inverseRelationship('categories');
    }
}
