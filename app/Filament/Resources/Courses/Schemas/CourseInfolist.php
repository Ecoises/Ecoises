<?php

namespace App\Filament\Resources\Courses\Schemas;

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
use Illuminate\Support\HtmlString;


class CourseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Group::make()
                ->schema([
                    Section::make('Información del curso')
                    ->icon('heroicon-o-rectangle-stack')
                    ->schema([
                        TextEntry::make('title')
                            ->weight('bold')
                            ->size('lg')
                            ->hiddenLabel(),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->markdown(),
                            // En un formulario o infolist (ej. CourseResource.php)
                        ImageEntry::make('thumbnail_url')
                            ->view('thumbnail-img')  // Esto buscará en resources/views/thumbnail-img.blade.php
                            ->label('Miniatura')
                            ->disk('public'),
                    ])
                    
                    ->collapsible(),

                    Section::make('Lecciones')
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('lessons')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('title')
                                    ->label('Lección')
                                    ->weight('bold'),

                                TextEntry::make('content_text')
                                    ->label('Contenido')
                                    ->html()
                                    ->alignJustify(),
                                    TextEntry::make('audio_url')
                                    ->label('Audio de esta lección')
                                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(
                                        '<audio controls class="w-full max-w-md">
                                            <source src="' . $state . '" type="audio/mpeg">
                                            Tu navegador no soporta el elemento de audio.
                                        </audio>'
                                    )),
                            ]),
                        RepeatableEntry::make('activities')
                            ->label('Actividades')
                            ->schema([
                                TextEntry::make('activity_type')
                                    ->label('Tipo')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state): string => match ($state) {
                                        'quiz_multiple' => 'Selección múltiple',
                                        'quiz_true_false' => 'Verdadero/Falso',
                                        'drag_drop' => 'Arrastrar y soltar',
                                        'matching' => 'Emparejar',
                                        default => $state,
                                    }),

                                TextEntry::make('title')
                                    ->label('Pregunta/Enunciado'),

                                TextEntry::make('content_data')
                                    ->label('Respuestas/Opciones')
                                    ->formatStateUsing(fn (?array $state): string => 
                                        $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : 'Sin datos'
                                    )
                                    ->copyable(),

                                TextEntry::make('explanation')
                                    ->label('Feedback')
                                    ->default('Sin feedback'),
                            ])
                            ->visible(fn ($record) => $record->type === 'modular'),    
                    ])
                    ->collapsible(),
                ])->columnSpan(['lg' => 2]),

            Group::make()
                ->schema([
                    Section::make('Mas información')
                    ->icon('heroicon-o-rectangle-stack')
                    ->schema([
                      
                        ImageEntry::make('author.avatar')
                            ->label('Avatar')
                            ->imageHeight(40)
                            ->circular(),
                        
                        TextEntry::make('author.full_name')
                            ->label('Autor'),
                            
                        TextEntry::make('created_at')
                            ->label('Creado el')
                            ->formatStateUsing(fn (string $state): string => 
                                \Carbon\Carbon::parse($state)->format('d/m/Y H:i')
                            ),
                        
                        TextEntry::make('updated_at')
                            ->label('Actualizado el')
                            ->formatStateUsing(fn (string $state): string => 
                                \Carbon\Carbon::parse($state)->format('d/m/Y H:i')
                            ),
                            TextEntry::make('difficulty_level')
                            ->label('Dificultad')
                            ->badge()
                            ->color('primary')
                            ->formatStateUsing(fn (string $state): string => ucwords($state)),

                            TextEntry::make('category')
                            ->label('Categoria')
                            ->badge()
                            ->color('primary')
                            ->formatStateUsing(fn (string $state): string => ucwords($state)),
                            
                         
                        
                    
                        
                    ])
                    ->columns(2)
                    ->collapsible(),

                
                    
                ])->columnSpan(['lg' => 1]),    

            
                

            
        ])->columns(3);
    }
}
