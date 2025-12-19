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
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\ToggleButtons;

use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;

use Filament\Actions\Action;
use Hugomyb\FilamentMediaAction\Actions\MediaAction;

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
                                        ->icon('heroicon-o-speaker-wave')
                                        ->collapsible()
                                        ->schema([
                                            ToggleButtons::make('voice_id')
                                                ->label('Selecciona una voz')
                                                ->options([
                                                    '94zOad0g7T7K4oa7zhDq' => 'Mauricio',
                                                    'V6isiXLBuRuM7uwHOVBA' => 'Luisa',
                                                    'W1hAcdh0RNsPYUA7fkJh' => 'El Faraón',
                                                ])
                                                ->icons([
                                                    '94zOad0g7T7K4oa7zhDq' => 'heroicon-m-microphone',
                                                    'V6isiXLBuRuM7uwHOVBA' => 'heroicon-m-microphone',
                                                    'W1hAcdh0RNsPYUA7fkJh' => 'heroicon-m-bolt',
                                                ])
                                                ->colors([
                                                    '94zOad0g7T7K4oa7zhDq' => 'info',
                                                    'V6isiXLBuRuM7uwHOVBA' => 'danger',
                                                    'W1hAcdh0RNsPYUA7fkJh' => 'warning',
                                                ])
                                                ->default('94zOad0g7T7K4oa7zhDq')
                                                ->required()
                                                ->inline()
                                                ->live(),
                                            Hidden::make('audio_timestamps'),
                                            Hidden::make('audio_url')
                                                ->live(),// ← CRUCIAL para reactividad
                                        ])
                                        ->footerActions([
                                            MediaAction::make('preview_voice')
                                                ->label('Previsualizar voz')
                                                ->icon('heroicon-o-speaker-wave')
                                                ->color('info')
                                                ->visible(fn (Get $get) => blank($get('audio_url')))
                                                ->media(fn (Get $get) => match ($get('voice_id')) {
                                                    '94zOad0g7T7K4oa7zhDq' => asset('audio/voice_preview_mauricio.mp3'),
                                                    'V6isiXLBuRuM7uwHOVBA' => asset('audio/voice_preview_luisa.mp3'),
                                                    'W1hAcdh0RNsPYUA7fkJh' => asset('audio/voice_preview_elfaraon.mp3'),
                                                    default => null,
                                                })
                                                ->mediaType(MediaAction::TYPE_AUDIO)
                                                ->modalHeading('Previsualización de la voz')
                                                ->preload(false)
                                                ->disableDownload(),

                                            /*────────────────────────────────
                                            | ESCUCHAR AUDIO GENERADO (solo si YA existe)
                                            ────────────────────────────────*/
                                            MediaAction::make('listen_generated_audio')
                                                ->label('Escuchar audio generado')
                                                ->icon('heroicon-o-play')
                                                ->color('success')
                                                ->visible(fn (Get $get) => filled($get('audio_url')))
                                                ->media(fn (Get $get) => $get('audio_url'))
                                                ->mediaType(MediaAction::TYPE_AUDIO)
                                                ->modalHeading('Audio generado')
                                                ->preload(false)
                                                ->disableDownload(),

                                            /*────────────────────────────────
                                            | GENERAR / REGENERAR AUDIO
                                            ────────────────────────────────*/
                                            Action::make('generate_audio')
                                                ->label(fn (Get $get) =>
                                                    filled($get('audio_url'))
                                                        ? 'Regenerar audio'
                                                        : 'Generar audio'
                                                )
                                                ->icon('heroicon-m-speaker-wave')
                                                ->color('primary')
                                                ->requiresConfirmation()
                                                ->modalHeading(fn (Get $get) =>
                                                    filled($get('audio_url'))
                                                        ? 'Regenerar audio'
                                                        : 'Generar audio'
                                                )
                                                ->modalDescription('El audio se generará a partir del contenido de la lección.')
                                                ->action(function (Set $set, Get $get) {
                                                    $text = strip_tags($get('content_text') ?? '');
                                                    $voiceId = $get('voice_id');

                                                    if (! $text) {
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
                                        ->orderColumn('activity_order')
                                        ->visible(fn (Get $get) => $get('../../type') === 'modular')
                                        ->schema([
                                            Select::make('activity_type')
                                                ->label('Tipo de Actividad')
                                                ->options([
                                                    'quiz_multiple'   => 'Selección múltiple',
                                                    'quiz_true_false' => 'Verdadero/Falso',
                                                    'drag_drop'       => 'Arrastrar y soltar',
                                                    'matching'        => 'Emparejar',
                                                ])
                                                ->required()
                                                ->reactive()
                                                ->afterStateUpdated(fn (Set $set) => $set('content_data', null)),

                                            Textarea::make('title')
                                                ->label('Pregunta/Enunciado')
                                                ->required(),

                                            Section::make('Configuración Específica')
                                                ->schema([
                                                    // Configuración dinámica para Selección Múltiple
                                                    Repeater::make('content_data.options')
                                                        ->label('Opciones de Respuesta')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_multiple')
                                                        ->schema([
                                                            TextInput::make('text')
                                                                ->label('Opción')
                                                                ->required(),

                                                            Toggle::make('is_correct')
                                                                ->label('Es correcta')
                                                                ->default(false),

                                                            Textarea::make('feedback')
                                                                ->label('Feedback personal a esta opción')
                                                                ->rows(2)
                                                                ->placeholder('Explicación para esta opción (correcta o incorrecta)'),
                                                        ])
                                                        ->defaultItems(2),

                                                    // Configuración dinámica para Verdadero/Falso
                                                    Radio::make('content_data.is_true')
                                                        ->label('Respuesta Correcta: ¿Es Verdadero?')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                                                        ->options([
                                                            'true' => 'Verdadero',
                                                            'false' => 'Falso',
                                                        ])
                                                        ->inline()
                                                        ->default(false),

                                                    Textarea::make('content_data.feedback')
                                                        ->label('Feedback para la respuesta Verdadero/Falso')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                                                        ->rows(3)
                                                        ->placeholder('Explica por qué esta afirmación es verdadera o falsa'),
                                                    
                                                    // Configuración dinámica para Arrastrar y Soltar
                                                    KeyValue::make('content_data.items')
                                                        ->label('Elementos a arrastrar')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'drag_drop')
                                                        ->keyLabel('Elemento')
                                                        ->valueLabel('Destino'),

                                                    Textarea::make('content_data.feedback')
                                                        ->label('Feedback general del orden o diseño')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'drag_drop')
                                                        ->rows(2),

                                                    // Configuración dinámica para Emparejamiento
                                                    Repeater::make('content_data.pairs')
                                                        ->label('Pares a Emparejar')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'matching')
                                                        ->schema([
                                                            TextInput::make('term')
                                                                ->label('Término')
                                                                ->required(),

                                                            TextInput::make('match')
                                                                ->label('Emparejamiento Correcto')
                                                                ->required(),

                                                            Textarea::make('feedback')
                                                                ->label('Feedback para este par')
                                                                ->rows(2),
                                                        ])
                                                        ->defaultItems(1),
                                                ])
                                                ->collapsible(),
                                                

                                            Textarea::make('explanation')
                                                ->label('Feedback general de la actividad')
                                                ->rows(3)
                                                ->placeholder('Explicación general para los resultados de esta actividad'),
                                        ])
                                        ->collapsible(),
                                ])
                                ->collapsible(),
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