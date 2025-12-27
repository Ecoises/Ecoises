<?php

namespace App\Filament\Resources\EducationalContents\Schemas;

use App\Models\EducationalContent;
use App\Models\Lesson; // Ensure Lesson model exists and has estimateReadingTime if used
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

class EducationalContentForm
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
                            Grid::make(1)
                                ->schema([
                                    TextInput::make('title')
                                        ->label('Título del contenido')
                                        ->required()
                                        ->maxLength(255)
                                        ->live(debounce: 500)
                                        ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug($state))),

                                    TextInput::make('slug')
                                        ->required()
                                        ->unique('educational_content', 'slug', ignoreRecord: true)
                                        ->dehydrated()
                                        ->readOnly(),

                                    Hidden::make('author_id')
                                        ->default(fn () => auth()->id()),

                                    RichEditor::make('description')
                                        ->label('Breve descripción'),
                                ])
                                ->columnSpan(2),

                            Grid::make(1)
                                ->schema([
                                    FileUpload::make('thumbnail_url')
                                        ->label('Imagen de portada')
                                        ->disk('public')
                                        ->directory('content/thumbnails')
                                        ->image()
                                        ->maxSize(2048)
                                        ->imageEditor()
                                        ->panelAspectRatio('16:9'),
                                ])
                                ->columnSpan(1),

                            Grid::make(2)
                                ->schema([
                                    Select::make('content_type')
                                        ->label('Tipo de Contenido')
                                        ->options([
                                            'course' => 'Curso Modular',
                                            'article' => 'Artículo Simple',
                                        ])
                                        ->default('course')
                                        ->required()
                                        ->live(),

                                    Select::make('categories')
                                        ->relationship('categories', 'name')
                                        ->multiple()
                                        ->preload()
                                        ->searchable(),

                                    TagsInput::make('tags')
                                        ->separator(','),

                                    Select::make('status')
                                        ->options([
                                            'draft' => 'Borrador',
                                            'reviewed' => 'Revisado',
                                            'published' => 'Publicado',
                                        ])
                                        ->default('draft')
                                        ->required(),

                                    // Detalles de Curso
                                    TextInput::make('course_details.completion_points')
                                        ->label('Puntos por completar')
                                        ->numeric()
                                        ->visible(fn (Get $get) => $get('content_type') === 'course'),
                                        
                                    // Detalles de Artículo
                                    TextInput::make('article_details.read_time')
                                        ->label('Tiempo de lectura (min)')
                                        ->numeric()
                                        ->visible(fn (Get $get) => $get('content_type') === 'article'),

                                    TextInput::make('estimated_duration')
                                        ->label('Duración Total (segundos)')
                                        ->numeric()
                                        ->default(0)
                                        ->readOnly(),
                                ])
                                ->columnSpanFull(),
                        ])
                        ->columns(3),

                    /*───────────────────────────────
                     | PASO 2 — CONTENIDO Y ESTRUCTURA
                     ───────────────────────────────*/
                    Step::make('Contenido')
                        ->schema([
                            // SECCIÓN CURSOS: Lecciones
                            Repeater::make('lessons')
                                ->label('Lecciones del Curso')
                                ->relationship('lessons')
                                ->visible(fn (Get $get) => $get('content_type') === 'course')
                                ->orderColumn('lesson_order')
                                ->defaultItems(1)
                                ->schema([
                                    TextInput::make('title')->required(),
                                    RichEditor::make('content_text')->required(),
                                    
                                    Section::make('Audio de la Lección')
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
                                                ->live(),
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

                                            Action::make('generate_audio')
                                                ->label(fn (Get $get) => filled($get('audio_url')) ? 'Regenerar audio' : 'Generar audio')
                                                ->icon('heroicon-m-speaker-wave')
                                                ->color('primary')
                                                ->requiresConfirmation()
                                                ->modalHeading(fn (Get $get) => filled($get('audio_url')) ? 'Regenerar audio' : 'Generar audio')
                                                ->modalDescription('El audio se generará a partir del contenido de la lección.')
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
                                                        $duration = $result['duration']; // Seconds
                                                        
                                                        // Update Lesson Duration
                                                        // Note: We might need helper logic to calc HH:MM:SS if the field expects string
                                                        // But previous form used HH:MM:SS. Here we need parity.
                                                        // Let's assume estimated_duration on Lesson is seconds or string?
                                                        // In CTI, estimated_duration is on EducationalContent, but Lessons have their own.
                                                        // If Lesson model has 'estimated_duration', we update it.
                                                        
                                                        // Simplified duration set (assuming seconds or helper handles format)
                                                        $set('estimated_duration', $duration); 

                                                        Notification::make()->title('Audio generado correctamente')->success()->send();
                                                    } catch (\Throwable $e) {
                                                        Notification::make()->title('Error al generar audio')->body($e->getMessage())->danger()->send();
                                                    }
                                                }),
                                        ])
                                        ->collapsed(),
                                        
                                    Repeater::make('activities')
                                        ->relationship('activities')
                                        ->schema(self::getActivitySchema())
                                        ->collapsed()
                                        ->label('Actividades de Lección'),
                                ])
                                ->collapsed()
                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),

                            // SECCIÓN ARTÍCULOS: Contenido Directo + Actividades
                             Grid::make(1)
                                ->visible(fn (Get $get) => $get('content_type') === 'article')
                                ->schema([
                                    RichEditor::make('article_details.content_text')
                                        ->label('Contenido del Artículo')
                                        ->required(),
                                    
                                    Section::make('Audio del Artículo')
                                        ->icon('heroicon-o-speaker-wave')
                                        ->collapsible()
                                        ->schema([
                                            ToggleButtons::make('article_details.voice_id')
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
                                            Hidden::make('article_details.audio_timestamps'),
                                            Hidden::make('article_details.audio_url')
                                                ->live(),
                                        ])
                                        ->footerActions([
                                            MediaAction::make('preview_voice_article') // Unique name
                                                ->label('Previsualizar voz')
                                                ->icon('heroicon-o-speaker-wave')
                                                ->color('info')
                                                ->visible(fn (Get $get) => blank($get('article_details.audio_url')))
                                                ->media(fn (Get $get) => match ($get('article_details.voice_id')) {
                                                    '94zOad0g7T7K4oa7zhDq' => asset('audio/voice_preview_mauricio.mp3'),
                                                    'V6isiXLBuRuM7uwHOVBA' => asset('audio/voice_preview_luisa.mp3'),
                                                    'W1hAcdh0RNsPYUA7fkJh' => asset('audio/voice_preview_elfaraon.mp3'),
                                                    default => null,
                                                })
                                                ->mediaType(MediaAction::TYPE_AUDIO)
                                                ->modalHeading('Previsualización de la voz')
                                                ->preload(false)
                                                ->disableDownload(),

                                            MediaAction::make('listen_generated_audio_article') // Unique name
                                                ->label('Escuchar audio generado')
                                                ->icon('heroicon-o-play')
                                                ->color('success')
                                                ->visible(fn (Get $get) => filled($get('article_details.audio_url')))
                                                ->media(fn (Get $get) => $get('article_details.audio_url'))
                                                ->mediaType(MediaAction::TYPE_AUDIO)
                                                ->modalHeading('Audio generado')
                                                ->preload(false)
                                                ->disableDownload(),

                                            Action::make('generate_audio_article')
                                                ->label(fn (Get $get) => filled($get('article_details.audio_url')) ? 'Regenerar audio' : 'Generar audio')
                                                ->icon('heroicon-m-speaker-wave')
                                                ->color('primary')
                                                ->requiresConfirmation()
                                                ->modalHeading(fn (Get $get) => filled($get('article_details.audio_url')) ? 'Regenerar audio' : 'Generar audio')
                                                ->modalDescription('El audio se generará a partir del contenido del artículo.')
                                                ->action(function (Set $set, Get $get) {
                                                    $text = strip_tags($get('article_details.content_text') ?? '');
                                                    $voiceId = $get('article_details.voice_id');

                                                    if (! $text) {
                                                        Notification::make()->title('Contenido vacío')->danger()->send();
                                                        return;
                                                    }

                                                    try {
                                                        $service = new ElevenLabsService;
                                                        $result = $service->generate($text, $voiceId);

                                                        $set('article_details.audio_url', $result['audio_url']);
                                                        $set('article_details.audio_timestamps', $result['audio_timestamps']);
                                                        $duration = $result['duration']; 
                                                        
                                                        // Update Educational Content estimated_duration (Total)
                                                        $set('estimated_duration', $duration);

                                                        Notification::make()->title('Audio generado correctamente')->success()->send();
                                                    } catch (\Throwable $e) {
                                                        Notification::make()->title('Error al generar audio')->body($e->getMessage())->danger()->send();
                                                    }
                                                }),
                                        ])
                                        ->collapsed(),

                                    Repeater::make('activities')
                                        ->label('Actividades del Artículo')
                                        ->relationship('activities') // MorphMany
                                        ->schema(self::getActivitySchema())
                                        ->collapsed(),
                                ]),
                        ]),

                    /*───────────────────────────────
                     | PASO 3 — PUBLICACIÓN
                     ───────────────────────────────*/
                    Step::make('Publicación')
                        ->schema([
                            Toggle::make('is_published')->label('Publicar ahora'),
                        ]),
                ])
                ->columnSpanFull(),
            ]);
    }

    public static function getActivitySchema(): array
    {
        return [
            Select::make('activity_type')
                ->options([
                    'quiz_multiple' => 'Selección Múltiple',
                    'quiz_true_false' => 'Verdadero/Falso',
                    'drag_drop' => 'Arrastrar y Soltar',
                    'matching' => 'Emparejar',
                ])
                ->live()
                ->required(),

            Textarea::make('title')
                ->label('Pregunta/Enunciado')
                ->required(),

            Section::make('Configuración')
                ->schema([
                    // Quiz Multiple
                    Repeater::make('content_data.options')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_multiple')
                        ->schema([
                            TextInput::make('text')->required(),
                            Toggle::make('is_correct'),
                            Textarea::make('feedback'),
                        ]),
                    
                    // True/False
                    Radio::make('content_data.is_true')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                        ->options(['true' => 'Verdadero', 'false' => 'Falso']),
                        
                    // Drag Drop
                    KeyValue::make('content_data.items')
                        ->visible(fn (Get $get) => $get('activity_type') === 'drag_drop'),
                        
                    // Matching
                    Repeater::make('content_data.pairs')
                        ->visible(fn (Get $get) => $get('activity_type') === 'matching')
                        ->schema([
                            TextInput::make('term')->required(),
                            TextInput::make('match')->required(),
                        ]),
                ]),
        ];
    }
}
