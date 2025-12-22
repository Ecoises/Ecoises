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
                    Section::make('Información de la Guía')
                    ->icon('heroicon-o-book-open')
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

                    Section::make('Contenido')
                    ->icon('heroicon-o-queue-list')
                    ->collapsible()
                    ->schema([
                        // --- Contenido para Artículos (Simples) ---
                        Group::make([
                            TextEntry::make('content_text')
                                ->label('Contenido del artículo')
                                ->html()
                                ->alignJustify()
                                ->columnSpanFull(),
                            
                            TextEntry::make('audio_url')
                                ->label('Audio del artículo')
                                ->formatStateUsing(fn (?string $state): ?HtmlString => $state ? new HtmlString(
                                    '<audio controls class="w-full max-w-md">
                                        <source src="' . $state . '" type="audio/mpeg">
                                        Tu navegador no soporta el elemento de audio.
                                    </audio>'
                                ) : null)
                                ->visible(fn ($record) => filled($record->audio_url)),
                        ])
                        ->visible(fn ($record) => $record->type === 'simple'),

                        // --- Contenido para Cursos Modulares ---
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
                                    ->formatStateUsing(fn (?string $state): ?HtmlString => $state ? new HtmlString(
                                        '<audio controls class="w-full max-w-md">
                                            <source src="' . $state . '" type="audio/mpeg">
                                            Tu navegador no soporta el elemento de audio.
                                        </audio>'
                                    ) : null),

                                RepeatableEntry::make('activities')
                                    ->label('Actividades de esta lección')
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
                                            ->formatStateUsing(fn ($state): string => 
                                                is_array($state) 
                                                    ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) 
                                                    : (string) ($state ?: 'Sin datos')
                                            )
                                            ->copyable(),

                                        TextEntry::make('explanation')
                                            ->label('Feedback')
                                            ->default('Sin feedback'),
                                    ]),
                            ])
                            ->visible(fn ($record) => $record->type === 'modular'),
                    ])
                    ->collapsible(),
                ])->columnSpan(['lg' => 2]),

                Group::make()
                ->schema([
                    Section::make('Mas información')
                    ->icon('heroicon-o-information-circle')
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

                            TextEntry::make('estimated_duration')
                                ->label('Duración Total')
                                ->formatStateUsing(function ($state) {
                                    if (!$state) return '00:00:00';
                                    $hours = floor($state / 3600);
                                    $minutes = floor(($state % 3600) / 60);
                                    $seconds = $state % 60;
                                    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                                }),

                            TextEntry::make('difficulty_level')
                            ->label('Dificultad')
                            ->badge()
                            ->color('primary')
                            ->formatStateUsing(fn (string $state): string => ucwords($state)),

                            TextEntry::make('category.name')
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
