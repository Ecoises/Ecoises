<?php

namespace App\Filament\Resources\EducationalContents\Schemas;

use App\Models\EducationalContent;
use App\Models\EducationalContentAsset;
use App\Models\Lesson; // Ensure Lesson model exists and has estimateReadingTime if used
use App\Services\ElevenLabsService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
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
                Section::make('Borrador protegido')
                    ->description(fn ($livewire): string => filled($livewire->lastAutosavedAt ?? null)
                        ? "Último guardado automático: {$livewire->lastAutosavedAt}. Puedes cerrar esta página y continuar después."
                        : 'Tus cambios se guardan automáticamente en la base de datos cada 10 segundos.')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->schema([])
                    ->visible(fn (string $operation, ?EducationalContent $record): bool => $operation === 'edit' && $record?->status === EducationalContent::STATUS_DRAFT
                    )
                    ->extraAttributes([
                        'x-data' => '{}',
                        'x-init' => 'const autosaveTimer = setInterval(() => $wire.autosaveDraft(), 10000); $cleanup(() => clearInterval(autosaveTimer))',
                    ]),

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
                                        ->live(debounce: 1500)
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
                                        ->maxHeight('60px')
                                        ->live(debounce: 2500),
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
                                        ->options(EducationalContent::getTypes())
                                        ->placeholder('Selecciona el tipo de contenido')
                                        ->helperText('Elige con cuidado: el tipo queda fijado cuando comienza a guardarse la estructura.')
                                        ->required()
                                        ->native(false)
                                        ->live()
                                        ->disabled(fn (?EducationalContent $record): bool => filled($record?->content_type)),

                                    Select::make('categories')
                                        ->relationship('categories', 'name')
                                        ->label('Área temática')
                                        ->multiple()
                                        ->preload()
                                        ->searchable()
                                        ->createOptionForm([
                                            TextInput::make('name')
                                                ->label('Nombre de Categoría')
                                                ->required()
                                                ->live(onBlur: true)
                                                ->afterStateUpdated(fn (Set $set, $state) => $set('slug', Str::slug($state))),
                                            TextInput::make('slug')
                                                ->required()
                                                ->readOnly(),
                                        ]),

                                    TagsInput::make('tags')
                                        ->label('Etiquetas')
                                        ->separator(','),

                                    // Detalles de Curso
                                    TextInput::make('course_details.completion_points')
                                        ->label('Puntos por completar')
                                        ->numeric()
                                        ->visible(fn (Get $get) => $get('content_type') === 'course')
                                        ->default(100),

                                    Toggle::make('course_details.has_certificate')
                                        ->label('Emitir certificado al completar')
                                        ->helperText('Se genera un certificado verificable cuando el estudiante termina el curso.')
                                        ->visible(fn (Get $get) => $get('content_type') === 'course')
                                        ->default(false),

                                    Select::make('course_details.prerequisite_content_ids')
                                        ->label('Contenidos prerrequisito')
                                        ->helperText('El estudiante deberá completarlos antes de iniciar este curso.')
                                        ->options(fn (): array => EducationalContent::query()
                                            ->where('content_type', EducationalContent::TYPE_COURSE)
                                            ->published()
                                            ->orderBy('title')
                                            ->pluck('title', 'id')
                                            ->all())
                                        ->multiple()
                                        ->searchable()
                                        ->preload()
                                        ->visible(fn (Get $get) => $get('content_type') === 'course'),

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
                                ->mutateRelationshipDataBeforeCreateUsing(
                                    fn (array $data): ?array => self::prepareLessonForDraft($data)
                                )
                                ->mutateRelationshipDataBeforeSaveUsing(
                                    fn (array $data): ?array => self::prepareLessonForDraft($data, false)
                                )
                                ->defaultItems(1)
                                ->schema([
                                    TextInput::make('title')
                                        ->label('Título de la lección')
                                        ->required()
                                        ->live(onBlur: true)
                                        ->afterStateUpdated(fn (Set $set, $state) => $set('slug', Str::slug($state))),

                                    Hidden::make('slug'),

                                    Hidden::make('estimated_duration'),

                                    RichEditor::make('content_text')
                                        ->resizableImages()
                                        ->label('Contenido de la Lección')
                                        ->required()
                                        ->fileAttachmentsDisk('public')
                                        ->fileAttachmentsDirectory('content/lessons')
                                        ->fileAttachmentsVisibility('public')
                                        ->live(debounce: 2500)
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

                                            return "[{$index}] ".Str::limit($state['citation'] ?? '', 40);
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
                                        ->collapsed()
                                        ->cloneable()
                                        ->addActionLabel('Añadir nueva fuente')
                                        ->defaultItems(0),

                                    Repeater::make('activities')
                                        ->relationship('activities')
                                        ->orderColumn('activity_order')
                                        ->mutateRelationshipDataBeforeCreateUsing(
                                            fn (array $data): ?array => self::prepareActivityForDraft($data)
                                        )
                                        ->mutateRelationshipDataBeforeSaveUsing(
                                            fn (array $data): ?array => self::prepareActivityForDraft($data)
                                        )
                                        ->schema(self::getActivitySchema())
                                        ->collapsible()
                                        ->label('Actividades interactivas')
                                        ->defaultItems(0)
                                        ->addActionLabel('Añadir nueva actividad')
                                        ->reorderableWithButtons()
                                        ->itemLabel(fn (array $state): ?string => $state['activity_type'] ?? null)
                                        ->collapsed(),
                                ])
                                ->collapsible()
                                ->defaultItems(1)
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
                                        ->fileAttachmentsDisk('public')
                                        ->fileAttachmentsDirectory('content/articles')
                                        ->fileAttachmentsVisibility('public')
                                        ->live(debounce: 2500)
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
                                        ->collapsed()
                                        ->label('Bibliografía y Referencias')
                                        ->itemLabel(function (array $state, $uuid, $component): string {
                                            $index = array_search($uuid, array_keys($component->getState())) + 1;

                                            return "[{$index}] ".Str::limit($state['citation'] ?? '', 40);
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
                                        ->mutateRelationshipDataBeforeCreateUsing(
                                            fn (array $data): ?array => self::prepareActivityForDraft($data)
                                        )
                                        ->mutateRelationshipDataBeforeSaveUsing(
                                            fn (array $data): ?array => self::prepareActivityForDraft($data)
                                        )
                                        ->schema(self::getActivitySchema())
                                        ->defaultItems(0)
                                        ->addActionLabel('Añadir nueva actividad')
                                        ->reorderableWithButtons()
                                        ->collapsible()
                                        ->collapsed(),
                                ]),

                            Section::make('Recursos visuales y descargables')
                                ->description(fn (Get $get): string => $get('content_type') === EducationalContent::TYPE_RESOURCE
                                    ? 'Este será el contenido principal. Añade una o varias imágenes, infografías, publicaciones PDF o enlaces.'
                                    : 'Material complementario opcional para enriquecer el contenido sin recargar la lectura.')
                                ->icon('heroicon-o-paper-clip')
                                ->visible(fn (Get $get): bool => filled($get('content_type')))
                                ->schema([
                                    Repeater::make('assets')
                                        ->hiddenLabel()
                                        ->relationship('assets')
                                        ->orderColumn('asset_order')
                                        ->mutateRelationshipDataBeforeCreateUsing(
                                            fn (array $data): ?array => self::prepareAssetForDraft($data)
                                        )
                                        ->mutateRelationshipDataBeforeSaveUsing(
                                            fn (array $data): ?array => self::prepareAssetForDraft($data)
                                        )
                                        ->schema([
                                            Select::make('asset_type')
                                                ->label('Tipo de recurso')
                                                ->options(EducationalContentAsset::getTypes())
                                                ->required()
                                                ->native(false)
                                                ->live(),

                                            TextInput::make('title')
                                                ->label('Título')
                                                ->required()
                                                ->maxLength(255),

                                            Textarea::make('description')
                                                ->label('Descripción o contexto')
                                                ->rows(2)
                                                ->columnSpanFull(),

                                            FileUpload::make('file_path')
                                                ->label('Archivo')
                                                ->disk('public')
                                                ->directory('content/resources')
                                                ->acceptedFileTypes([
                                                    'image/*',
                                                    'application/pdf',
                                                    'application/msword',
                                                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                                ])
                                                ->maxSize(20480)
                                                ->downloadable()
                                                ->openable()
                                                ->required(fn (Get $get): bool => $get('asset_type') !== EducationalContentAsset::TYPE_EXTERNAL_LINK)
                                                ->visible(fn (Get $get): bool => $get('asset_type') !== EducationalContentAsset::TYPE_EXTERNAL_LINK)
                                                ->helperText('Imágenes, infografías, PDF o Word. Máximo 20 MB.')
                                                ->columnSpanFull(),

                                            TextInput::make('external_url')
                                                ->label('Dirección del recurso')
                                                ->url()
                                                ->required(fn (Get $get): bool => $get('asset_type') === EducationalContentAsset::TYPE_EXTERNAL_LINK)
                                                ->visible(fn (Get $get): bool => $get('asset_type') === EducationalContentAsset::TYPE_EXTERNAL_LINK)
                                                ->columnSpanFull(),

                                            Toggle::make('is_downloadable')
                                                ->label('Permitir descarga')
                                                ->default(true)
                                                ->visible(fn (Get $get): bool => $get('asset_type') !== EducationalContentAsset::TYPE_EXTERNAL_LINK),
                                        ])
                                        ->columns(2)
                                        ->defaultItems(0)
                                        ->addActionLabel('Añadir recurso')
                                        ->reorderableWithButtons()
                                        ->collapsible()
                                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null),
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
                                            'pending' => 'Pendiente de revisión',
                                            'reviewed' => 'Revisado',
                                            'published' => 'Publicado',
                                        ])
                                        ->default('draft')
                                        ->disabled()
                                        ->dehydrated(false)
                                        ->helperText('El estado cambia mediante las acciones Enviar a revisión, Aprobar revisión y Publicar.'),
                                ]),
                        ]),

                ])
                    ->columnSpanFull()
                    ->submitAction(
                        Action::make('save')
                            ->label('Guardar')
                            ->submit('save')
                            ->color('primary')
                            ->icon('heroicon-o-folder-open')

                    ),
            ]);
    }

    public static function getAudioSectionSchema(string $prefix = ''): Section
    {
        $dot = filled($prefix) ? '.' : '';

        return Section::make('Audio Narrado')
            ->description('Genera una versión en audio profesional de tu contenido.')
            ->icon('heroicon-o-speaker-wave')
            ->collapsed()
            ->schema([
                ToggleButtons::make("{$prefix}{$dot}voice_id")
                    ->label('Selecciona una voz')
                    ->options([
                        'Charon' => 'Charon',
                        'Aoede' => 'Aoede',
                        'Puck' => 'Puck',
                    ])
                    ->default('Charon')
                    ->afterStateHydrated(fn ($component, $state) => blank($state) ? $component->state('Charon') : null)
                    ->required()
                    ->inline()
                    ->live(),

                Hidden::make("{$prefix}{$dot}audio_url")->live(),
                Hidden::make("{$prefix}{$dot}audio_timestamps"),
            ])
            ->footerActions([
                MediaAction::make('listen')
                    ->label('Escuchar')
                    ->icon('heroicon-o-play')
                    ->color('secondary')
                    ->visible(fn (Get $get) => filled($get("{$prefix}{$dot}audio_url")))
                    ->media(fn (Get $get) => $get("{$prefix}{$dot}audio_url"))
                    ->mediaType(MediaAction::TYPE_AUDIO),

                Action::make('generate')
                    ->label(fn (Get $get) => filled($get("{$prefix}{$dot}audio_url")) ? 'Regenerar audio' : 'Generar audio')
                    ->icon('heroicon-m-speaker-wave')
                    ->color('primary')
                    ->modalHeading('Generar audio narrado')
                    ->modalDescription('Guardaremos el borrador y generaremos la narración en segundo plano. Puedes continuar trabajando mientras termina.')
                    ->modalSubmitActionLabel('Generar')
                    ->requiresConfirmation()
                    ->action(function (Get $get, $record, $livewire) use ($prefix, $dot) {
                        $text = strip_tags($get(filled($prefix) ? "{$prefix}.content_text" : 'content_text') ?? '');

                        if (blank($text)) {
                            Notification::make()
                                ->title('No hay contenido')
                                ->body('Escribe el contenido antes de generar audio.')
                                ->warning()
                                ->send();

                            return;
                        }

                        // Persiste silenciosamente el texto actual antes de enviar el
                        // trabajo. Ya no hace falta guardar, salir y volver a editar.
                        if (method_exists($livewire, 'autosaveDraft')) {
                            $livewire->autosaveDraft();
                        }

                        $parentRecord = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

                        // Determinar el modelo y contexto
                        $target = null;
                        $contextInfo = [];

                        if ($prefix === 'article_details') {
                            if (! $parentRecord || ! $parentRecord->exists) {
                                Notification::make()
                                    ->title('No se pudo proteger el borrador')
                                    ->body('Intenta nuevamente en unos segundos.')
                                    ->warning()
                                    ->send();

                                return;
                            }
                            $target = $parentRecord->fresh();
                            $contextInfo = [
                                'type' => 'article',
                                'title' => $target->title,
                            ];
                        } else {
                            $target = $record instanceof Lesson ? $record->fresh() : null;

                            if (! $target && $parentRecord) {
                                $target = Lesson::query()
                                    ->where('content_id', $parentRecord->id)
                                    ->where('slug', $get('slug'))
                                    ->first();
                            }

                            if (! $target) {
                                Notification::make()
                                    ->title('La lección aún se está guardando')
                                    ->body('Espera unos segundos y vuelve a generar el audio.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $contextInfo = [
                                'type' => 'lesson',
                                'lesson_title' => $target->title,
                                'educational_resource_title' => $target->content?->title ?? 'Recurso Educativo',
                            ];
                        }

                        $voiceId = $get("{$prefix}{$dot}voice_id") ?? 'Charon';
                        $contextInfo['source_hash'] = hash('sha256', preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));

                        \App\Jobs\ProcessAudioFull::dispatch(
                            $target,
                            $text,
                            $voiceId,
                            auth()->user(),
                            $prefix,
                            $contextInfo
                        );

                        Notification::make()
                            ->title('Generando audio...')
                            ->body('Te notificaremos cuando esté listo.')
                            ->info()
                            ->send();
                    }),
            ]);
    }

    protected static function prepareLessonForDraft(array $data, bool $isCreating = true): ?array
    {
        if ($isCreating && blank($data['title'] ?? null) && blank(strip_tags($data['content_text'] ?? ''))) {
            return null;
        }

        $data['title'] = filled($data['title'] ?? null) ? $data['title'] : 'Lección sin título';
        $data['slug'] = filled($data['slug'] ?? null)
            ? $data['slug']
            : Str::slug($data['title']).'-'.Str::lower(Str::random(8));
        $data['estimated_duration'] = (int) ($data['estimated_duration'] ?? 0);

        // El audio lo escribe exclusivamente el trabajo en segundo plano. Así un
        // formulario abierto con estado antiguo no puede borrarlo después.
        unset($data['audio_url'], $data['audio_timestamps']);

        return $data;
    }

    protected static function prepareActivityForDraft(array $data): ?array
    {
        if (blank($data['activity_type'] ?? null) || blank($data['title'] ?? null)) {
            return null;
        }

        $data['content_data'] = is_array($data['content_data'] ?? null)
            ? $data['content_data']
            : [];

        return $data;
    }

    protected static function prepareAssetForDraft(array $data): ?array
    {
        if (blank($data['asset_type'] ?? null)
            || blank($data['title'] ?? null)
            || (blank($data['file_path'] ?? null) && blank($data['external_url'] ?? null))) {
            return null;
        }

        if (($data['asset_type'] ?? null) === EducationalContentAsset::TYPE_EXTERNAL_LINK) {
            $data['file_path'] = null;
            $data['is_downloadable'] = false;
        } else {
            $data['external_url'] = null;
        }

        return $data;
    }

    // public static function getAudioSectionSchema(string $prefix = ''): Section
    // {
    //     $dot = filled($prefix) ? '.' : '';

    //     return Section::make('Audio del Contenido')
    //         ->description('Selecciona una voz para generar automáticamente la versión en audio del contenido redactado.')
    //         ->icon('heroicon-o-speaker-wave')
    //         ->collapsed()
    //         ->collapsible()
    //         ->schema([
    //             ToggleButtons::make("{$prefix}{$dot}voice_id")
    //                 ->label('Selecciona una voz')
    //                 ->options([
    //                     '94zOad0g7T7K4oa7zhDq' => 'Mauricio',
    //                     'V6isiXLBuRuM7uwHOVBA' => 'Luisa',
    //                     'W1hAcdh0RNsPYUA7fkJh' => 'El Faraón',
    //                 ])
    //                 ->icons([
    //                     '94zOad0g7T7K4oa7zhDq' => 'heroicon-m-microphone',
    //                     'V6isiXLBuRuM7uwHOVBA' => 'heroicon-m-microphone',
    //                     'W1hAcdh0RNsPYUA7fkJh' => 'heroicon-m-microphone',
    //                 ])
    //                 ->colors([
    //                     '94zOad0g7T7K4oa7zhDq' => 'info',
    //                     'V6isiXLBuRuM7uwHOVBA' => 'danger',
    //                     'W1hAcdh0RNsPYUA7fkJh' => 'warning',
    //                 ])
    //                 ->default('94zOad0g7T7K4oa7zhDq')
    //                 ->required()
    //                 ->inline()
    //                 ->live(),

    //             Hidden::make("{$prefix}{$dot}audio_timestamps"),
    //             Hidden::make("{$prefix}{$dot}audio_url")->live(),
    //         ])
    //         ->footerActions([
    //             MediaAction::make('preview_voice')
    //                 ->label('Previsualizar voz')
    //                 ->icon('heroicon-o-play')
    //                 ->color('secondary')
    //                 ->visible(fn (Get $get) => blank($get("{$prefix}{$dot}audio_url")))
    //                 ->media(fn (Get $get) => match ($get("{$prefix}{$dot}voice_id")) {
    //                     '94zOad0g7T7K4oa7zhDq' => asset('audio/voice_preview_mauricio.mp3'),
    //                     'V6isiXLBuRuM7uwHOVBA' => asset('audio/voice_preview_luisa.mp3'),
    //                     'W1hAcdh0RNsPYUA7fkJh' => asset('audio/voice_preview_elfaraon.mp3'),
    //                     default => null,
    //                 })
    //                 ->mediaType(MediaAction::TYPE_AUDIO)
    //                 ->modalHeading('Previsualización de la voz')
    //                 ->disableDownload(),

    //             MediaAction::make('listen_generated_audio')
    //                 ->label('Escuchar audio generado')
    //                 ->icon('heroicon-o-play')
    //                 ->color('success')
    //                 ->visible(fn (Get $get) => filled($get("{$prefix}{$dot}audio_url")))
    //                 ->media(fn (Get $get) => $get("{$prefix}{$dot}audio_url"))
    //                 ->mediaType(MediaAction::TYPE_AUDIO)
    //                 ->modalHeading('Audio generado')
    //                 ->disableDownload(),

    //             Action::make('generate_audio')
    //                 ->label(fn (Get $get) => filled($get("{$prefix}{$dot}audio_url")) ? 'Regenerar audio' : 'Generar audio')
    //                 ->icon('heroicon-m-speaker-wave')
    //                 ->color('primary')
    //                 ->requiresConfirmation()
    //                 ->action(function (Set $set, Get $get) use ($prefix, $dot) {
    //                     $sourceField = filled($prefix) ? "{$prefix}.content_text" : "content_text";
    //                     $text = strip_tags($get($sourceField) ?? '');

    //                     $voiceId = $get("{$prefix}{$dot}voice_id");

    //                     if (empty(trim($text))) {
    //                         Notification::make()->title('Contenido vacío')->danger()->send();
    //                         return;
    //                     }

    //                     try {
    //                         $service = new ElevenLabsService;
    //                         $result = $service->generate($text, $voiceId);

    //                         $set("{$prefix}{$dot}audio_url", $result['audio_url']);

    //                         // Guardamos solo el array de palabras con timestamps (formato limpio y útil)
    //                         $set("{$prefix}{$dot}audio_timestamps", json_encode($result['audio_timestamps']['words'] ?? []));

    //                         // Actualizar duración estimada
    //                         $set('estimated_duration', $result['duration']);

    //                         Notification::make()
    //                             ->title('Audio generado correctamente')
    //                             ->success()
    //                             ->send();
    //                     } catch (\Throwable $e) {
    //                         Notification::make()
    //                             ->title('Error al generar audio')
    //                             ->body($e->getMessage())
    //                             ->danger()
    //                             ->send();
    //                     }
    //                 }),
    //         ]);
    // }

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
                            TextInput::make('text')
                                ->required()
                                ->label('Escribe una opción'),
                            Toggle::make('is_correct')
                                ->label('Es la respuesta correcta'),
                            Textarea::make('feedback')
                                ->label('Explicación')
                                ->placeholder('Explica brevemente por qué esta opción es (o no) la correcta.'),
                        ])
                        ->addActionLabel('Añadir otra opción')
                        ->defaultItems(2)
                        ->reorderableWithButtons(),

                    // True/False - Versión simplificada
                    Radio::make('content_data.correct_answer')
                        ->label('¿Cuál es la respuesta correcta para esta afirmación?')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                        ->options([
                            'true' => 'Verdadero',
                            'false' => 'Falso',
                        ])
                        ->inline()
                        ->required()
                        ->default('true'),

                    Textarea::make('content_data.feedback_correct')
                        ->label('Retroalimentación correcta')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                        ->placeholder('Ej: ¡Correcto! Esta afirmación es verdadera porque...')
                        ->rows(3)
                        ->required(),

                    Textarea::make('content_data.feedback_incorrect')
                        ->label('Retroalimentación incorrecta')
                        ->visible(fn (Get $get) => $get('activity_type') === 'quiz_true_false')
                        ->placeholder('Ej: No es correcto. La respuesta correcta es falso porque...')
                        ->rows(3)
                        ->required(),

                    // Drag Drop — Categorías
                    Repeater::make('content_data.categories')
                        ->label('Categorías')
                        ->helperText('Crea categorías y asigna palabras o elementos a cada una. El estudiante deberá arrastrar cada elemento a su categoría correcta.')
                        ->visible(fn (Get $get) => $get('activity_type') === 'drag_drop')
                        ->schema([
                            TextInput::make('name')
                                ->label('Nombre de la categoría')
                                ->placeholder('Ej: Mamíferos, Reptiles, Aves...')
                                ->required(),
                            TagsInput::make('items')
                                ->label('Elementos de esta categoría')
                                ->placeholder('Escribe un elemento y presiona Enter')
                                ->helperText('Añade las palabras o frases que pertenecen a esta categoría.')
                                ->required(),
                        ])
                        ->columns(1)
                        ->addActionLabel('Añadir categoría')
                        ->defaultItems(2)
                        ->reorderableWithButtons(),

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
                        ->reorderableWithButtons(),
                ]),
        ];
    }
}
