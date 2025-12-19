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
                            ]),
                    ])
                    ->collapsible(),
                ])->columnSpan(['lg' => 2]),

            
                

            Section::make('Lecciones')
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('lessons')
                        ->schema([
                            TextEntry::make('title')
                                ->label('Lección')
                                ->weight('bold'),

                            TextEntry::make('content_text')
                                ->label('Contenido')
                                ->markdown(),
                        ]),
                ])->columnSpan(1),
        ])->columns(3);
    }
}
