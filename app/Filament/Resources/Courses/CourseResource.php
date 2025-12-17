<?php

namespace App\Filament\Resources\Courses;

use BackedEnum;
use App\Models\Course;
use App\Services\ElevenLabsService;

use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;

use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;

use Filament\Actions\Action;

use App\Filament\Resources\Courses\Pages\CreateCourse;
use App\Filament\Resources\Courses\Pages\EditCourse;
use App\Filament\Resources\Courses\Pages\ListCourses;
use App\Filament\Resources\Courses\Tables\CoursesTable;

class CourseResource extends Resource
{
    protected static ?  string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?  string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    /*───────────────────────────────
                     | PASO 1 — INFORMACIÓN GENERAL
                     ───────────────────────────────*/
                    Step::make('Información General')
                        ->schema([
                            TextInput::make('title')
                                ->label('Título del curso / lección')
                                ->required()
                                ->maxLength(255),

                            RichEditor::make('description')
                                ->label('Breve descripción'),

                            Select::make('type')
                                ->label('Tipo de contenido')
                                ->options([
                                    'modular' => 'Curso modular',
                                    'simple'  => 'Lección simple',
                                ])
                                ->default('modular')
                                ->required()
                                ->live(),

                            Select::make('category')
                                ->label('Categoría')
                                ->options([
                                    'taxonomia'      => 'Taxonomía',
                                    'ecologia'       => 'Ecología',
                                    'conservacion'   => 'Conservación',
                                    'identificacion' => 'Identificación',
                                    'botanica'       => 'Botánica',
                                    'zoologia'       => 'Zoología',
                                    'general'        => 'General',
                                ])
                                ->required(),

                            FileUpload::make('thumbnail_url')
                                ->label('Imagen de portada')
                                ->image()
                                ->directory('courses/thumbnails')
                                ->imageEditor(),
                        ]),

                    /*───────────────────────────────
                     | PASO 2 — CONTENIDO Y AUDIO
                     ───────────────────────────────*/
                    Step::make('Contenido y Audio')
                        ->schema([
                            Repeater::make('lessons')
                                ->relationship('lessons')
                                ->label('Lecciones')
                                ->defaultItems(1)
                                ->schema([
                                    TextInput:: make('title')
                                        ->label('Título de la lección')
                                        ->required(),

                                    RichEditor::make('content_text')
                                        ->label('Contenido')
                                        ->required(),

                                    Section::make('Generación de audio')
                                        ->collapsible()
                                        ->collapsed()
                                        ->schema([
                                            Select::make('voice_id')
                                                ->label('Selecciona una voz')
                                                ->options([
                                                    '94zOad0g7T7K4oa7zhDq' => 'Mauricio',
                                                    'V6isiXLBuRuM7uwHOVBA' => 'Luisa',
                                                    'W1hAcdh0RNsPYUA7fkJh' => 'El Faraón',
                                                ])
                                                ->default('94zOad0g7T7K4oa7zhDq')
                                                ->required()
                                                ->live(),

                                            View::make('filament.components.voice-preview')
                                                ->viewData([
                                                    'voicePreviewUrls' => [
                                                        '94zOad0g7T7K4oa7zhDq' => '/audio/voice_preview_mauricio.mp3',
                                                        'V6isiXLBuRuM7uwHOVBA' => '/audio/voice_preview_luisa.mp3',
                                                        'W1hAcdh0RNsPYUA7fkJh' => '/audio/voice_preview_faraon.mp3',
                                                    ],
                                                ])
                                                ->columnSpanFull(),

                                            Hidden::make('audio_url'),
                                            Hidden::make('audio_timestamps'),
                                        ])
                                        ->footerActions([
                                            Action::make('generate_audio')
                                                ->label('Generar audio')
                                                ->icon('heroicon-m-speaker-wave')
                                                ->color('primary')
                                                ->requiresConfirmation()
                                                ->modalHeading('Generar audio')
                                                ->modalDescription('Se generará el audio y los timestamps desde el contenido.')
                                                ->action(function (Set $set, Get $get) {
                                                    $text = strip_tags($get('content_text') ?? '');
                                                    $voiceId = $get('voice_id');

                                                    if (!  $text) {
                                                        Notification::make()
                                                            ->title('Contenido vacío')
                                                            ->danger()
                                                            ->send();
                                                        return;
                                                    }

                                                    try {
                                                        $service = new ElevenLabsService();
                                                        $result = $service->generate($text, $voiceId);

                                                        $set('audio_url', $result['audio_url']);
                                                        $set('audio_timestamps', $result['audio_timestamps']);

                                                        Notification::make()
                                                            ->title('Audio generado correctamente')
                                                            ->success()
                                                            ->send();
                                                    } catch (\Throwable $e) {
                                                        Notification::make()
                                                            ->title('Error al generar audio')
                                                            ->body($e->getMessage())
                                                            ->danger()
                                                            ->send();
                                                    }
                                                }),
                                        ]),

                                    Repeater::make('lesson_activities')
                                        ->relationship('activities')
                                        ->label('Actividades')
                                        ->visible(fn (Get $get) => $get('../../type') === 'modular')
                                        ->schema([
                                            Select::make('activity_type')
                                                ->label('Tipo')
                                                ->options([
                                                    'quiz_multiple'   => 'Selección múltiple',
                                                    'quiz_true_false' => 'Verdadero / Falso',
                                                    'drag_drop'       => 'Arrastrar y soltar',
                                                    'matching'        => 'Emparejar',
                                                ])
                                                ->required(),

                                            Textarea::make('title')
                                                ->label('Pregunta'),

                                            KeyValue::make('content_data')
                                                ->label('Datos'),

                                            KeyValue::make('correct_answers')
                                                ->label('Respuestas correctas'),
                                        ])
                                        ->collapsible()
                                        ->collapsed(),
                                ])
                                ->collapsible()
                                ->collapsed(),
                        ]),

                    /*───────────────────────────────
                     | PASO 3 — PUBLICACIÓN
                     ───────────────────────────────*/
                    Step::make('Referencias y Publicación')
                        ->schema([
                            Repeater::make('references')
                                ->label('Referencias')
                                ->schema([
                                    TextInput::make('author')->label('Autor'),
                                    TextInput::make('year')->label('Año')->numeric(),
                                    TextInput:: make('title')->label('Título'),
                                    TextInput:: make('url')->label('URL')->url(),
                                ])
                                ->columns(4)
                                ->collapsible(),

                            Toggle::make('is_published')
                                ->label('Publicar')
                                ->default(false),
                        ]),
                ])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return CoursesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCourses::route('/'),
            'create' => CreateCourse::route('/create'),
            'edit'   => EditCourse::route('/{record}/edit'),
        ];
    }
}