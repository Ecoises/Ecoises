<?php

namespace App\Filament\Resources\EducationalContents\Schemas;

use App\Filament\Infolists\Components\ActivityContentEntry;
use Filament\Infolists\Components\SpatieMediaLibraryImageEntry;
use Filament\Infolists\Components\SpatieTagsEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\ImageEntry;





class EducationalContentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Group::make()
                ->schema([
                    // Bloque de información base (Título y Descripción)
                    Section::make('Información General')
                        ->icon('heroicon-o-information-circle')
                        ->schema([
                            TextEntry::make('title')->weight('bold')->size('lg')->hiddenLabel(),
                            ImageEntry::make('thumbnail_url')
                            ->view('thumbnail-img')  // Esto buscará en resources/views/thumbnail-img.blade.php
                            ->label('Miniatura')
                            ->disk('public'),
                            TextEntry::make('description')->markdown(),
                        ])->collapsible(),

                    // CASO 1: ES UN ARTÍCULO
                    Section::make('Contenido del Artículo')
                        ->icon('heroicon-o-document-text')
                        ->collapsible()
                        ->visible(fn ($record) => $record->content_type === 'article')
                        ->schema([
                            TextEntry::make('articleDetails.content_text')
                                ->hiddenLabel()
                                ->html()
                                ->alignJustify()
                                ->columnSpanFull(),
                            
                            TextEntry::make('articleDetails.audio_url')
                                ->label('Audio')
                                ->formatStateUsing(fn ($state) => $state ? new HtmlString("<audio controls class='w-full'><source src='{$state}'></audio>") : null)
                                ->visible(fn ($record) => filled($record->articleDetails?->audio_url)),

                            Section::make('Actividades de Aprendizaje')
                                ->description('Actividades prácticas asociadas a este contenido')
                                ->icon('heroicon-o-puzzle-piece')
                                ->visible(fn ($record) => $record->activities()->exists())
                                ->schema([
                                    RepeatableEntry::make('activities')
                                        ->hiddenLabel()
                                        ->schema([
                                            ActivityContentEntry::make('content_data')
                                            ->hiddenLabel(), 
                                        ])
                                        ->grid(1)
                                        ->contained(false)
                                ]),
                        ]),

                    // CASO 2: ES UN CURSO (MODULAR)
                    Section::make('Módulos y Lecciones')
                        ->icon('heroicon-o-academic-cap')
                        ->visible(fn ($record) => $record->content_type === 'course')
                        ->schema([
                            // Primer nivel: LECCIONES (Relación hasMany en el modelo CourseDetails)
                            RepeatableEntry::make('lessons')
                                ->label('Lecciones')
                                ->schema([
                                    TextEntry::make('title')
                                        ->label('Título de la Lección')
                                        ->weight('bold')
                                        ->icon('heroicon-m-book-open'),
                                    
                                    TextEntry::make('content_text')
                                        ->label('Contenido de la Lección')
                                        ->html()
                                        ->alignJustify(),
                                        
                                        
                                     TextEntry::make('audio_url')
                                        ->label('Audio de esta lección')
                                        
                                        ->formatStateUsing(fn (?string $state): ?HtmlString => $state ? new HtmlString(
                                            '<audio controls class="w-full max-w-md">
                                                <source src="' . $state . '" type="audio/mpeg">
                                                No hay audio disponible.
                                            </audio>'
                                        ) : null),

                                    // Segundo nivel: ACTIVIDADES (Relación polimórfica en el modelo Lesson)
                                    Section::make('Actividades de Aprendizaje')
                                    ->description('Actividades prácticas asociadas a este contenido')
                                    ->icon('heroicon-o-puzzle-piece')
                                   
                                    ->collapsed()
                                    ->visible(fn ($record) => $record->activities()->exists())
                                    ->schema([
                                        RepeatableEntry::make('activities')
                                            ->hiddenLabel()
                                            ->schema([
                                               ActivityContentEntry::make('content_data')
                                                ->hiddenLabel(), 
                                            ])
                                            ->grid(1)
                                            ->contained(false)
                                    ]),
                                ])
                        ]),
                ])->columnSpan(['lg' => 2]),

            Group::make()
                ->schema([
                    Section::make('Detalles Adicionales')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->schema([
                        Section::make('Autor')
                        ->icon('heroicon-m-user')
                        ->schema([
                            ImageEntry::make('author.avatar')
                                ->hiddenLabel()
                                ->imageHeight(40)
                                ->circular()
                                ->alignCenter(),

                            TextEntry::make('author.full_name')
                                ->hiddenLabel()
                                ->alignCenter(),
                        ])
                        ->columns(1)
                        ->compact(),
                        
                        // Grupo para los campos que quieres en 2 columnas
                        Group::make()
                        ->schema([
                            TextEntry::make('created_at')
                                ->label('Creado el')
                                ->dateTime('d M, Y') 
                                ->size('sm'), 
                            
                            TextEntry::make('updated_at')
                                ->label('Actualizado el')
                                ->dateTime('d M, Y')
                                ->size('sm'), 
                        ])
                        ->columns(2),
                        
                        // Grupo para los campos que quieres en 1 columna (normal)
                        Group::make()
                        ->schema([
                            TextEntry::make('status')
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
                                }),

                            TextEntry::make('difficulty_level')
                            ->label('Dificultad')
                            ->badge()
                            ->color('primary')
                            ->formatStateUsing(fn (string $state): string => ucwords($state)),

                            TextEntry::make('categories.name')
                            ->label('Categoria')
                            ->badge()
                            ->color('primary'),

                            
                        ])
                        ->columns(2), 
                        TextEntry::make('tags')
                            ->label('Etiquetas')
                            ->badge()
                            ->separator(',')
                            ->color('gray'),// Una sola columna para estos campos
                    ])
                    ->collapsible(),
                ])->columnSpan(['lg' => 1]),    

            
        ])->columns(3);
    }
}
