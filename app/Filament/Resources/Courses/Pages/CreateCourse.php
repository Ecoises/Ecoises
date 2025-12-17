<?php

namespace App\Filament\Resources\Courses\Pages;

use App\Filament\Resources\Courses\CourseResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Wizard\Concerns\HasWizard;

class CreateCourse extends CreateRecord
{
    // use HasWizard;

    protected static string $resource = CourseResource::class;

    // protected function getSteps(): array
    // {
    //     return [
    //         Step::make('Información General')
    //             ->schema([
    //                 TextInput::make('title')
    //                     ->label('Título del curso / lección')
    //                     ->required()
    //                     ->maxLength(255),

    //                 RichEditor::make('description')
    //                     ->label('Breve descripción'),

    //                 Select::make('type')
    //                     ->label('Tipo de contenido')
    //                     ->options([
    //                         'modular' => 'Curso modular',
    //                         'simple'  => 'Lección simple',
    //                     ])
    //                     ->default('modular')
    //                     ->required()
    //                     ->live(),

    //                 Select::make('category')
    //                     ->label('Categoría')
    //                     ->options([
    //                         'taxonomia'      => 'Taxonomía',
    //                         'ecologia'       => 'Ecología',
    //                         'conservacion'   => 'Conservación',
    //                         'identificacion' => 'Identificación',
    //                         'botanica'       => 'Botánica',
    //                         'zoologia'       => 'Zoología',
    //                         'general'        => 'General',
    //                     ])
    //                     ->required(),

    //                 TextInput::make('completion_points')
    //                     ->label('Puntos por completar el curso')
    //                     ->numeric()
    //                     ->default(100)
    //                     ->required(),

    //                 Hidden::make('author_id')
    //                     ->default(fn () => auth()->id()),

    //                 FileUpload::make('thumbnail_url')
    //                     ->label('Imagen de portada')
    //                     ->image()
    //                     ->directory('courses/thumbnails')
    //                     ->imageEditor(),
    //             ]),

    //         Step::make('Contenido y Audio')
    //             ->schema([
    //                 Repeater::make('lessons')
    //                     ->relationship('lessons')
    //                     ->label('Lecciones')
    //                     ->orderColumn('lesson_order')
    //                     ->defaultItems(1)
    //                     ->schema([
    //                         TextInput:: make('title')
    //                             ->label('Título de la lección')
    //                             ->required(),

    //                         RichEditor::make('content_text')
    //                             ->label('Contenido')
    //                             ->required()
    //                             ->columnSpanFull(),

    //                         TextInput::make('points')
    //                             ->label('Puntos')
    //                             ->numeric()
    //                             ->default(10)
    //                             ->required()
    //                             ->columnSpan(1),

    //                         TextInput::make('estimated_duration')
    //                             ->label('Duración estimada (segundos)')
    //                             ->numeric()
    //                             ->readOnly()
    //                             ->columnSpan(1),

    //                         FileUpload::make('content_audio')
    //                             ->label('Audio')
    //                             ->directory('courses/lessons')
    //                             ->imageEditor(),
    //                     ])
    //                     ->collapsible()
    //                     ->collapsed(),
    //             ]),
            
    //             S
    //     ];
    // }
    
}
