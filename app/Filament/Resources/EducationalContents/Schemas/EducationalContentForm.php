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
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
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
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn ($state, Set $set) => $set('slug', Str::slug($state))),

                                    TextInput::make('slug')
                                        ->required()
                                        ->unique('educational_content', 'slug', ignoreRecord: true)
                                        ->dehydrated()
                                        ->readOnly(),

                                    Hidden::make('author_id')
                                        ->default(fn () => auth()->id()),

                                    MarkdownEditor::make('description')
                                        ->label('Breve descripción')
                                        ->minHeight('50px') // Por defecto suele ser 300px o más
                                        ->maxHeight('60px'),
                                ])
                                ->columnSpan(2),

                            Grid::make(1)
                                ->schema([
                                    FileUpload::make('thumbnail_url')
                                        ->label('Imagen de portada')
                                        ->disk('public')
                                        ->directory('content/thumbnails')
                                        ->image()
                                        ->maxSize(10240)
                                        ->imageEditor()
                                        ->panelAspectRatio('16:9')
                                        ->helperText('Sube una imagen llamativa para la portada. Peso máximo: 10MB.')
                                        ->validationMessages([
                                            'max' => 'La imagen es muy pesada. Debe pesar menos de 10MB.',
                                        ]),
                                ])
                                ->columnSpan(1),

                            Section::make()
                                ->schema([])
                                ->columnSpanFull(),

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
                                        ->native(false)
                                        ->live(),

                                    Select::make('categories')
                                        ->relationship('categories', 'name')
                                        ->label('Área temática')
                                        ->multiple()
                                        ->preload()
                                        ->searchable(),

                                    TagsInput::make('tags')
                                        ->label('Etiquetas')
                                        ->separator(','),

                                    

                                    // Detalles de Curso
                                    TextInput::make('course_details.completion_points')
                                        ->label('Puntos por completar')
                                        ->numeric()
                                        ->visible(fn (Get $get) => $get('content_type') === 'course')
                                        ->default(100),

                                    TextInput::make('article_details.read_time')
                                    ->label('Tiempo de lectura (min)')
                                    ->numeric()
                                    ->prefix('aprox.')
                                    ->suffix('minutos')
                                    ->readonly()
                                    ->visible(fn (Get $get) => $get('content_type') === 'article')
                                    ->live(),
                                        
                                    

                                    
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
                                    TextInput::make('title')
                                    ->label('Título de la lección')
                                    ->required(),
                                    RichEditor::make('content_text')
                                    ->label('Contenido de la Lección')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->helperText(function (?string $state): string {
                                        // Mantenemos tu lógica de cálculo para el texto de ayuda
                                        $words = Str::wordCount(strip_tags($state ?? ''));
                                        $minutes = ceil($words / 200);

                                        return "Aquí puedes redactar el contenido educativo detallado para la lección. Estimación: {$minutes} min de lectura ({$words} palabras).";
                                    })
                                    ->afterStateUpdated(function (Set $set, ?string $state) {
                                        // 1. Verificamos si hay texto, si no, ponemos 0
                                        if (blank($state)) {
                                            $set('estimated_duration', 0);
                                            return;
                                        }

                                        // 2. Limpiamos el HTML
                                        $plainText = strip_tags($state);
                                        
                                        // 3. Contamos palabras
                                        $words = Str::wordCount($plainText);
                                        
                                        // 4. Calculamos minutos (estándar 200 palabras/min)
                                        $minutes = (int) ceil($words / 200);
                                        
                                        // 5. Asignamos el valor a la columna de la lección
                                        // IMPORTANTE: Aquí cambiamos 'article_details.read_time' por 'estimated_duration'
                                        $set('estimated_duration', max(1, $minutes));
                                    }),
                                    
                                    self::getAudioSectionSchema(),

                                    Repeater::make('references')
                                    ->label('Bibliografía y Referencias')
                                    ->itemLabel(function (array $state, $uuid, $component): string {
                                        $index = array_search($uuid, array_keys($component->getState())) + 1;
                                        
                                        return "[{$index}] " . Str::limit($state['citation'] ?? '', 40);
                                    })
                                    ->schema([
                                        Group::make([
                                            Textarea::make('citation')
                                                ->hiddenLabel()
                                                ->placeholder('Pega aquí la cita bibliográfica...')
                                                ->rows(1)
                                                ->autosize()
                                                ->required()
                                                ->grow(),
                                        ])->columns(1),
                                    ])
                                    ->reorderable()
                                    ->reorderableWithButtons()
                                    ->collapsible()
                                    ->cloneable()
                                    ->addActionLabel('Añadir nueva fuente')
                                    ->defaultItems(0),
                                        
                                    Repeater::make('activities')
                                        ->relationship('activities')
                                        ->orderColumn('activity_order')
                                        ->schema(self::getActivitySchema())
                                        ->collapsible()
                                        ->label('Actividades interactivas')
                                        ->defaultItems(0)
                                        ->addActionLabel('Añadir nueva actividad')
                                        ->reorderableWithButtons(),
                                ])
                                ->collapsible()
                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),

                            // SECCIÓN ARTÍCULOS: Contenido Directo + Actividades
                             Grid::make(1)
                                ->visible(fn (Get $get) => $get('content_type') === 'article')
                                ->schema([
                                    RichEditor::make('article_details.content_text')
                                        ->label('Contenido del Artículo')
                                        ->helperText(function (?string $state): string {
                                            $words = Str::wordCount(strip_tags($state));
                                            $minutes = ceil($words / 200);

                                            return "Aquí puedes redactar el contenido educativo detallado para el artículo. Estimación: {$minutes} min de lectura ({$words} palabras).";
                                        })
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(function (Set $set, ?string $state) { // El ? permite que sea nulo
                                            // 1. Verificamos si hay texto, si no, ponemos 0 o 1
                                            if (blank($state)) {
                                                $set('article_details.read_time', 0);
                                                return;
                                            }

                                            // 2. Limpiamos el HTML
                                            $plainText = strip_tags($state);
                                            
                                            // 3. Contamos palabras (usando el helper de Laravel para soporte multi-idioma)
                                            $words = Str::wordCount($plainText);
                                            
                                            // 4. Calculamos (200 palabras por minuto es el estándar)
                                            $minutes = (int) ceil($words / 200);
                                            
                                            // 5. Asignamos el valor al campo del JSON
                                            $set('article_details.read_time', max(1, $minutes));
                                        }),
                                    
                                    self::getAudioSectionSchema('article_details'),

                                    Repeater::make('references')
                                    ->label('Bibliografía y Referencias')
                                    ->itemLabel(function (array $state, $uuid, $component): string {
                                        $index = array_search($uuid, array_keys($component->getState())) + 1;
                                        
                                        return "[{$index}] " . Str::limit($state['citation'] ?? '', 40);
                                    })
                                    ->schema([
                                        Group::make([
                                            Textarea::make('citation')
                                                ->hiddenLabel()
                                                ->placeholder('Pega aquí la cita bibliográfica...')
                                                ->rows(1)
                                                ->autosize()
                                                ->required()
                                                ->grow(),
                                        ])->columns(1),
                                    ])
                                    ->reorderable()
                                    ->reorderableWithButtons()
                                    ->collapsible()
                                    ->cloneable()
                                    ->addActionLabel('Añadir nueva fuente')
                                    ->defaultItems(0),

                                    Repeater::make('activities')
                                        ->label('Actividades del Artículo')
                                        ->relationship('activities')
                                        ->orderColumn('activity_order') // MorphMany
                                        ->schema(self::getActivitySchema())
                                        ->defaultItems(0)
                                        ->addActionLabel('Añadir nueva actividad')
                                        ->reorderableWithButtons()
                                        ->collapsible(),
                                ]),
                        ]),

                    /*───────────────────────────────
                     | PASO 3 — PUBLICACIÓN
                     ───────────────────────────────*/
                    Step::make('Publicación')
                        ->schema([
                            Group::make()
                            ->schema([
                                Select::make('status')
                                ->options([
                                    'draft' => 'Borrador',
                                    'reviewed' => 'Revisado',
                                    'published' => 'Publicado',
                                ])
                                ->default('draft')
                                ->required(),
                            // Detalles de Artículo
                                
                            Toggle::make('is_published')->label('Publicar ahora'),
                            ])->columns(2),
                        ])
                      
                ])
                ->columnSpanFull()
                ->submitAction(
                    Action::make('save')
                        ->label('Guardar')
                        ->submit('save')
                        ->color('primary')
                        ->icon('heroicon-o-folder-open')

                        
                    )
            ]);
    }

    public static function getAudioSectionSchema(string $prefix = ''): Section
    {
        $dot = filled($prefix) ? '.' : '';

        return Section::make('Audio del Contenido')
            ->description('Selecciona una voz para generar automáticamente la versión en audio del contenido redactado.')
            ->icon('heroicon-o-speaker-wave')
            ->collapsed()
            ->collapsible()
            ->schema([
                ToggleButtons::make("{$prefix}{$dot}voice_id")
                    ->label('Selecciona una voz')
                    ->options([
                        '94zOad0g7T7K4oa7zhDq' => 'Mauricio',
                        'V6isiXLBuRuM7uwHOVBA' => 'Luisa',
                        'W1hAcdh0RNsPYUA7fkJh' => 'El Faraón',
                    ])
                    ->icons([
                        '94zOad0g7T7K4oa7zhDq' => 'heroicon-m-microphone',
                        'V6isiXLBuRuM7uwHOVBA' => 'heroicon-m-microphone',
                        'W1hAcdh0RNsPYUA7fkJh' => 'heroicon-m-microphone',
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

                Hidden::make("{$prefix}{$dot}audio_timestamps"),
                Hidden::make("{$prefix}{$dot}audio_url")->live(),
            ])
            ->footerActions([
                MediaAction::make('preview_voice')
                    ->label('Previsualizar voz')
                    ->icon('heroicon-o-play')
                    ->color('secondary')
                    ->visible(fn (Get $get) => blank($get("{$prefix}{$dot}audio_url")))
                    ->media(fn (Get $get) => match ($get("{$prefix}{$dot}voice_id")) {
                        '94zOad0g7T7K4oa7zhDq' => asset('audio/voice_preview_mauricio.mp3'),
                        'V6isiXLBuRuM7uwHOVBA' => asset('audio/voice_preview_luisa.mp3'),
                        'W1hAcdh0RNsPYUA7fkJh' => asset('audio/voice_preview_elfaraon.mp3'),
                        default => null,
                    })
                    ->mediaType(MediaAction::TYPE_AUDIO)
                    ->modalHeading('Previsualización de la voz')
                    ->disableDownload(),

                MediaAction::make('listen_generated_audio')
                    ->label('Escuchar audio generado')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->visible(fn (Get $get) => filled($get("{$prefix}{$dot}audio_url")))
                    ->media(fn (Get $get) => $get("{$prefix}{$dot}audio_url"))
                    ->mediaType(MediaAction::TYPE_AUDIO)
                    ->modalHeading('Audio generado')
                    ->disableDownload(),

                Action::make('generate_audio')
                    ->label(fn (Get $get) => filled($get("{$prefix}{$dot}audio_url")) ? 'Regenerar audio' : 'Generar audio')
                    ->icon('heroicon-m-speaker-wave')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (Set $set, Get $get) use ($prefix, $dot) {
                        $sourceField = filled($prefix) ? "{$prefix}.content_text" : "content_text";
                        $text = strip_tags($get($sourceField) ?? '');

                        $voiceId = $get("{$prefix}{$dot}voice_id");

                        if (empty(trim($text))) {
                            Notification::make()->title('Contenido vacío')->danger()->send();
                            return;
                        }

                        try {
                            $service = new ElevenLabsService;
                            $result = $service->generate($text, $voiceId);

                            $set("{$prefix}{$dot}audio_url", $result['audio_url']);

                            // Guardamos solo el array de palabras con timestamps (formato limpio y útil)
                            $set("{$prefix}{$dot}audio_timestamps", json_encode($result['audio_timestamps']['words'] ?? []));

                            // Actualizar duración estimada
                            $set('estimated_duration', $result['duration']);

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
            ]);
        }

    public static function getActivitySchema(): array
    {
        return [
            Select::make('activity_type')
                ->label('Tipo de actividad')
                ->options([
                    'quiz_multiple' => 'Selección Múltiple',
                    'quiz_true_false' => 'Verdadero/Falso',
                    'drag_drop' => 'Arrastrar y Soltar',
                    'matching' => 'Emparejar',
                ])
                ->live()
                ->native(false)
                ->required(),

            Textarea::make('title')
                ->label('Pregunta/Enunciado')
                ->required(),

            Section::make('Diseño de Actividades')
                ->icon('heroicon-o-squares-plus')
                ->description('Configura el tipo de evaluación o ejercicio para este contenido')
                ->collapsible()
                ->schema([
                    // Quiz Multiple
                    Repeater::make('content_data.options')
                        ->label('Opciones de respuesta')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_multiple')
                        ->schema([
                            TextInput::make('text')->required()
                            ->label('Escribe una opción'),
                            Toggle::make('is_correct')
                            ->label('Es la respuesta correcta'),
                            Textarea::make('feedback')
                            ->label('Explicación')
                            ->placeholder('Explica brevemente por qué esta opción es (o no) la correcta.')
                            ,
                        ])
                        ->addActionLabel('Añadir otra opción')
                        ->defaultItems(2)
                        ->reorderableWithButtons(),
                    
                    // True/False
                    Radio::make('content_data.is_true')
                        ->label('Respuesta correcta')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                        ->options([
                            'true' => 'Verdadero',
                            'false' => 'Falso'
                        ])
                        ->inline(),
                    Textarea::make('content_data.true_false_feedback')
                        ->label('Explicación')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                        ->rows(3)
                        ->placeholder('Explica por qué esta afirmación es verdadera o falsa'),
                        
                    // Drag Drop
                    KeyValue::make('content_data.items')
                        ->label('Relacionar conceptos')
                        ->keyLabel('Palabra o frase')
                        ->valueLabel('Pertenece a...')
                        ->helperText('Crea una lista de elementos. A la izquierda la palabra clave y a la derecha su grupo o descripción.')
                        ->visible(fn (Get $get) => $get('activity_type') === 'drag_drop')
                        ->addActionLabel('Añadir elemento'),
                        
                    // Matching
                    Repeater::make('content_data.pairs')
                    ->label('Crear parejas')
                    ->helperText('Crea conexiones. Los usuarios deberán unir el lado A con el lado B.')
                    ->visible(fn (Get $get) => $get('activity_type') === 'matching')
                    ->schema([
                        TextInput::make('term')
                            ->label('Lado A')
                            ->placeholder('Pregunta o concepto')
                            ->required(),
                        TextInput::make('match')
                            ->label('Lado B')
                            ->placeholder('Respuesta o pareja')
                            ->required(),
                    ])
                    ->columns(2)
                    ->addActionLabel('Añadir nueva pareja')
                    ->reorderableWithButtons()
                ]),
        ];
    }
}
