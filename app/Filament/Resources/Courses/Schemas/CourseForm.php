<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\ElevenLabsService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Hugomyb\FilamentMediaAction\Actions\MediaAction;
use Illuminate\Support\Str;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    /*───────────────────────────────
                     | PASO 1 — INFORMACIÓN GENERAL
                     ───────────────────────────────*/
                    Step::make('Información General')
                        ->schema([
                            // Columna izquierda: Nombre y Descripción (más ancha)
                            Grid::make(1)
                                ->schema([
                                    TextInput::make('title')
                                        ->label('Título del curso / lección')
                                        ->required()
                                        ->maxLength(255),

                                    Hidden::make('author_id')
                                        ->default(fn () => auth()->id()),

                                    RichEditor::make('description')
                                        ->label('Breve descripción'),
                                ])
                                ->columnSpan(2), // Ocupa 2 de 3 columnas (66%)

                            // Columna derecha: Imagen (más pequeña)
                            Grid::make(1)
                                ->schema([
                                    FileUpload::make('thumbnail_url')
                                        ->label('Imagen de portada')
                                        ->disk('public')
                                        ->directory('courses/thumbnails')
                                        ->image()
                                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp'])
                                        ->maxSize(2048)
                                        ->imageEditor()
                                        ->imagePreviewHeight('250')
                                        ->panelAspectRatio('16:9')
                                        ->panelLayout('compact')
                                        ->live(),
                                ])
                                ->columnSpan(1),

                            Section::make()
                                ->schema([])
                                ->columnSpanFull(),

                            // Resto de campos en 2 columnas
                            Grid::make(2)
                                ->schema([
                                    Select::make('type')
                                        ->label('Tipo de contenido')
                                        ->options([
                                            'modular' => 'Curso modular',
                                            'simple' => 'Lección simple',
                                        ])
                                        ->default('modular')
                                        ->required()
                                        ->live(),

                                    Select::make('category_id')
                                        ->label('Categoría')
                                        ->relationship('category', 'name')
                                        ->searchable()
                                        ->preload()
                                        ->createOptionForm([
                                            TextInput::make('name')
                                                ->required()
                                                ->live(debounce: 500)
                                                ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug($state))),
                                            TextInput::make('slug')
                                                ->readOnly()
                                                ->dehydrated()
                                                ->required()
                                                ->unique('course_categories', 'slug', ignoreRecord: true),
                                        ])
                                        ->required(),

                                    TagsInput::make('tags')
                                        ->label('Etiquetas')
                                        ->separator(',')
                                        ->placeholder('Nueva etiqueta'),

                                    Select::make('status')
                                        ->label('Estado del contenido')
                                        ->options(Course::getStatuses())
                                        ->default(Course::STATUS_DRAFT)
                                        ->required(),

                                    TextInput::make('estimated_duration')
                                        ->label('Duración estimada total (HH:MM:SS)')
                                        ->placeholder('00:00:00')
                                        ->readOnly()
                                        ->dehydrated()
                                        ->columnSpanFull() // Ocupa todo el ancho de las 2 columnas
                                        ->afterStateHydrated(function (TextInput $component, $state) {
                                            if (filled($state) && ! str_contains((string) $state, ':')) {
                                                $state = (int) $state;
                                                $hours = floor($state / 3600);
                                                $minutes = floor(($state % 3600) / 60);
                                                $seconds = $state % 60;
                                                $component->state(sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds));
                                            }
                                        })
                                        ->dehydrateStateUsing(function ($state) {
                                            if (empty($state)) {
                                                return 0;
                                            }
                                            if (is_numeric($state)) {
                                                return (int) $state;
                                            }
                                            $parts = explode(':', (string) $state);
                                            if (count($parts) !== 3) {
                                                return 0;
                                            }

                                            return ($parts[0] * 3600) + ($parts[1] * 60) + (int) $parts[2];
                                        }),
                                ])
                                ->columnSpanFull(),
                        ])
                        ->columns(3),

                    /*───────────────────────────────
                     | PASO 2 — CONTENIDO Y AUDIO
                     ───────────────────────────────*/
                    Step::make('Contenido y Audio')
                        ->schema([
                            Repeater::make('lessons')
                                ->relationship('lessons')
                                ->orderColumn('lesson_order')
                                ->label('Lecciones')
                                ->defaultItems(1)
                                ->visible(fn (Get $get) => $get('type') === 'modular')
                                ->live()
                                ->afterStateUpdated(function (Set $set, Get $get) {
                                    $lessons = $get('lessons') ?? [];
                                    $totalSeconds = 0;
                                    foreach ($lessons as $lesson) {
                                        $duration = $lesson['estimated_duration'] ?? 0;
                                        if (is_string($duration) && str_contains($duration, ':')) {
                                            $parts = explode(':', $duration);
                                            $totalSeconds += ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] ?? 0);
                                        } else {
                                            $totalSeconds += (int) $duration;
                                        }
                                    }

                                    $hours = floor($totalSeconds / 3600);
                                    $minutes = floor(($totalSeconds % 3600) / 60);
                                    $seconds = $totalSeconds % 60;
                                    $set('estimated_duration', sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds));
                                })
                                ->schema([
                                    TextInput::make('title')
                                        ->label('Título de la lección')
                                        ->required(),

                                    RichEditor::make('content_text')
                                        ->label('Contenido')
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                            if (! $get('audio_url')) {
                                                $duration = Lesson::estimateReadingTime($state);
                                                $hours = floor($duration / 3600);
                                                $minutes = floor(($duration % 3600) / 60);
                                                $seconds = $duration % 60;
                                                $set('estimated_duration', sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds));

                                                // --- Recalcular total del curso ---
                                                $lessons = $get('../../lessons') ?? [];
                                                $totalSeconds = 0;
                                                foreach ($lessons as $lesson) {
                                                    $lduration = $lesson['estimated_duration'] ?? 0;
                                                    if (is_string($lduration) && str_contains($lduration, ':')) {
                                                        $parts = explode(':', $lduration);
                                                        $totalSeconds += ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] ?? 0);
                                                    } else {
                                                        $totalSeconds += (int) $lduration;
                                                    }
                                                }
                                                $thours = floor($totalSeconds / 3600);
                                                $tminutes = floor(($totalSeconds % 3600) / 60);
                                                $tseconds = $totalSeconds % 60;
                                                $set('../../estimated_duration', sprintf('%02d:%02d:%02d', $thours, $tminutes, $tseconds));
                                            }
                                        }),

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
                                                ->live(), // ← CRUCIAL para reactividad
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
                                                ->label(fn (Get $get) => filled($get('audio_url'))
                                                        ? 'Regenerar audio'
                                                        : 'Generar audio'
                                                )
                                                ->icon('heroicon-m-speaker-wave')
                                                ->color('primary')
                                                ->requiresConfirmation()
                                                ->modalHeading(fn (Get $get) => filled($get('audio_url'))
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
                                                        $service = new ElevenLabsService;
                                                        $result = $service->generate($text, $voiceId);

                                                        $set('audio_url', $result['audio_url']);
                                                        $set('audio_timestamps', $result['audio_timestamps']);
                                                        $duration = $result['duration'];
                                                        $hours = floor($duration / 3600);
                                                        $minutes = floor(($duration % 3600) / 60);
                                                        $seconds = $duration % 60;
                                                        $set('estimated_duration', sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds));

                                                        // --- Recalcular total del curso ---
                                                        $lessons = $get('../../lessons') ?? [];
                                                        $totalSeconds = 0;
                                                        foreach ($lessons as $lesson) {
                                                            $lduration = $lesson['estimated_duration'] ?? 0;
                                                            if (is_string($lduration) && str_contains($lduration, ':')) {
                                                                $parts = explode(':', $lduration);
                                                                $totalSeconds += ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] ?? 0);
                                                            } else {
                                                                $totalSeconds += (int) $lduration;
                                                            }
                                                        }
                                                        $thours = floor($totalSeconds / 3600);
                                                        $tminutes = floor(($totalSeconds % 3600) / 60);
                                                        $tseconds = $totalSeconds % 60;
                                                        $set('../../estimated_duration', sprintf('%02d:%02d:%02d', $thours, $tminutes, $tseconds));

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

                                    TextInput::make('estimated_duration')
                                        ->label('Duración de la lección (HH:MM:SS)')
                                        ->placeholder('00:00:00')
                                        ->readOnly()
                                        ->dehydrated()
                                        ->afterStateHydrated(function (TextInput $component, $state) {
                                            if (filled($state) && ! str_contains((string) $state, ':')) {
                                                $state = (int) $state;
                                                $hours = floor($state / 3600);
                                                $minutes = floor(($state % 3600) / 60);
                                                $seconds = $state % 60;
                                                $component->state(sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds));
                                            }
                                        })
                                        ->dehydrateStateUsing(function ($state) {
                                            if (empty($state)) {
                                                return 0;
                                            }
                                            if (is_numeric($state)) {
                                                return (int) $state;
                                            }
                                            $parts = explode(':', (string) $state);
                                            if (count($parts) !== 3) {
                                                return 0;
                                            }

                                            return ($parts[0] * 3600) + ($parts[1] * 60) + (int) $parts[2];
                                        }),

                                    Select::make('status')
                                        ->label('Estado de la lección')
                                        ->options(Course::getStatuses())
                                        ->default(Course::STATUS_DRAFT)
                                        ->required(),

                                    Repeater::make('lesson_activities')
                                        ->relationship('activities')
                                        ->label('Actividades')
                                        ->orderColumn('activity_order')
                                        ->visible(fn (Get $get) => $get('../../type') === 'modular')
                                        ->schema([
                                            Select::make('activity_type')
                                                ->label('Tipo de Actividad')
                                                ->options([
                                                    'quiz_multiple' => 'Selección múltiple',
                                                    'quiz_true_false' => 'Verdadero/Falso',
                                                    'drag_drop' => 'Arrastrar y soltar',
                                                    'matching' => 'Emparejar',
                                                ])
                                                ->required()
                                                ->reactive(),

                                            Textarea::make('title')
                                                ->label('Pregunta/Enunciado')
                                                ->required(),

                                            Section::make('Configuración Específica')
                                                ->schema([
                                                    // Selección Múltiple
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
                                                        ->defaultItems(2)
                                                        ->collapsible()
                                                        ->cloneable(),

                                                    // Verdadero/Falso
                                                    Radio::make('content_data.is_true')
                                                        ->label('Respuesta Correcta')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                                                        ->options([
                                                            'true' => 'Verdadero',
                                                            'false' => 'Falso',
                                                        ])
                                                        ->inline()
                                                        ->required(),

                                                    Textarea::make('content_data.true_false_feedback')
                                                        ->label('Explicación')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                                                        ->rows(3)
                                                        ->placeholder('Explica por qué esta afirmación es verdadera o falsa'),

                                                    // Arrastrar y Soltar
                                                    KeyValue::make('content_data.items')
                                                        ->label('Elementos a arrastrar')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'drag_drop')
                                                        ->keyLabel('Elemento')
                                                        ->valueLabel('Destino')
                                                        ->addActionLabel('Agregar elemento')
                                                        ->required(),

                                                    Textarea::make('content_data.drag_drop_feedback')
                                                        ->label('Explicación del orden correcto')
                                                        ->visible(fn (Get $get) => $get('activity_type') === 'drag_drop')
                                                        ->rows(2),

                                                    // Emparejamiento
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
                                                        ->defaultItems(1)
                                                        ->collapsible()
                                                        ->cloneable()
                                                        ->required(),
                                                ])
                                                ->collapsible(),

                                        ])
                                        ->collapsible()
                                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
                                ])
                                ->collapsible(),

                            // --- Seccion para Artículos (Lección Simple) ---
                            RichEditor::make('content_text')
                                ->label('Contenido del artículo')
                                ->visible(fn (Get $get) => $get('type') === 'simple')
                                ->required(fn (Get $get) => $get('type') === 'simple')
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Set $set, Get $get, $state) {
                                    if (! $get('audio_url')) {
                                        $duration = Lesson::estimateReadingTime($state);
                                        $hours = floor($duration / 3600);
                                        $minutes = floor(($duration % 3600) / 60);
                                        $seconds = $duration % 60;
                                        $set('estimated_duration', sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds));
                                    }
                                }),

                            Section::make('Generación de audio (Artículo)')
                                ->icon('heroicon-o-speaker-wave')
                                ->collapsible()
                                ->visible(fn (Get $get) => $get('type') === 'simple')
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
                                        ->inline()
                                        ->live(),
                                    Hidden::make('audio_timestamps'),
                                    Hidden::make('audio_url')
                                        ->live(),
                                ])
                                ->footerActions([
                                    MediaAction::make('preview_voice_article')
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

                                    MediaAction::make('listen_generated_audio_article')
                                        ->label('Escuchar audio generado')
                                        ->icon('heroicon-o-play')
                                        ->color('success')
                                        ->visible(fn (Get $get) => filled($get('audio_url')))
                                        ->media(fn (Get $get) => $get('audio_url'))
                                        ->mediaType(MediaAction::TYPE_AUDIO)
                                        ->modalHeading('Audio generado')
                                        ->preload(false)
                                        ->disableDownload(),

                                    Action::make('generate_audio_article')
                                        ->label(fn (Get $get) => filled($get('audio_url')) ? 'Regenerar audio' : 'Generar audio')
                                        ->icon('heroicon-m-speaker-wave')
                                        ->color('primary')
                                        ->requiresConfirmation()
                                        ->action(function (Set $set, Get $get) {
                                            $text = strip_tags($get('content_text') ?? '');
                                            $voiceId = $get('voice_id');
                                            if (! $text) {
                                                Notification::make()->title('Contenido vacío')->danger()->send();

                                                return;
                                            }
                                            try {
                                                $service = new ElevenLabsService;
                                                $result = $service->generate($text, $voiceId);
                                                $set('audio_url', $result['audio_url']);
                                                $set('audio_timestamps', $result['audio_timestamps']);
                                                $duration = $result['duration'];
                                                $hours = floor($duration / 3600);
                                                $minutes = floor(($duration % 3600) / 60);
                                                $seconds = $duration % 60;
                                                $set('estimated_duration', sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds));
                                                Notification::make()->title('Audio generado correctamente')->success()->send();
                                            } catch (\Throwable $e) {
                                                Notification::make()->title('Error al generar audio')->body($e->getMessage())->danger()->send();
                                            }
                                        }),
                                ]),
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
                                    TextInput::make('title')->label('Título'),
                                    TextInput::make('url')->label('URL')->url(),
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
}
