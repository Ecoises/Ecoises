<?php

namespace App\Filament\Resources\EducationalContents\Schemas;

use App\Filament\Infolists\Components\ActivityContentEntry;
use Filament\Infolists\Components\Group;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class EducationalContentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Group::make()
                    ->schema([
                        Section::make('Información del Contenido')
                            ->schema([
                                TextEntry::make('title')->label('Título'),
                                TextEntry::make('description')
                                    ->label('Descripción')
                                    ->html(),
                                ImageEntry::make('thumbnail_url')
                                    ->label('Portada')
                                    ->view('thumbnail-img'), // Custom view
                                TextEntry::make('categories.name')
                                    ->label('Categorías')
                                    ->badge(),
                                TextEntry::make('tags')
                                    ->badge()
                                    ->separator(','),
                            ])->columns(2),

                        Section::make('Detalles y Contenido')
                            ->schema([
                                // ARTÍCULO
                                Group::make([
                                    TextEntry::make('article_details.content_text')
                                        ->label('Contenido')
                                        ->html(),
                                    TextEntry::make('article_details.audio_url')
                                        ->label('Audio')
                                        ->formatStateUsing(fn ($state) => $state ? "<audio controls src='{$state}'></audio>" : 'Sin audio')
                                        ->html(),
                                    RepeatableEntry::make('activities')
                                        ->label('Actividades')
                                        ->schema([
                                            ActivityContentEntry::make('content_data'),
                                        ]),
                                ])->visible(fn ($record) => $record->content_type === 'article'),

                                // CURSO
                                Group::make([
                                    RepeatableEntry::make('lessons')
                                        ->label('Lecciones')
                                        ->schema([
                                            TextEntry::make('title')->label('Título'),
                                            TextEntry::make('content_text')->html()->label('Contenido'),
                                            TextEntry::make('audio_url')
                                                ->label('Audio')
                                                ->formatStateUsing(fn ($state) => $state ? "<audio controls src='{$state}'></audio>" : 'Sin audio')
                                                ->html(),
                                            RepeatableEntry::make('activities')
                                                ->label('Actividades')
                                                ->schema([
                                                    ActivityContentEntry::make('content_data'),
                                                ]),
                                        ]),
                                ])->visible(fn ($record) => $record->content_type === 'course'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
