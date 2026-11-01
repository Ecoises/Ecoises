<?php

namespace App\Filament\Resources\Announcements;

use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\EditAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Gestión de Contenido';

    protected static ?string $navigationLabel = 'Anuncios';

    protected static ?string $modelLabel = 'Anuncio';

    protected static ?string $pluralModelLabel = 'Anuncios';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make([
                    Section::make('Contenido')
                        ->description('Lo que verán las personas en la aplicación.')
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->schema([
                            TextInput::make('title')
                                ->label('Título')
                                ->required()
                                ->maxLength(255)
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (?string $state, Set $set) => $set('slug', Str::slug($state ?? ''))),
                            TextInput::make('slug')
                                ->label('Enlace')
                                ->required()
                                ->unique('announcements', 'slug', ignoreRecord: true)
                                ->readOnly(),
                            Hidden::make('author_id')->default(fn () => auth()->id()),
                            Textarea::make('summary')
                                ->label('Resumen breve')
                                ->helperText('Aparece en la tarjeta del anuncio. Máximo 500 caracteres.')
                                ->rows(2)
                                ->maxLength(500)
                                ->columnSpanFull(),
                            RichEditor::make('body')
                                ->label('Contenido')
                                ->fileAttachmentsDisk('public')
                                ->fileAttachmentsDirectory('announcements/attachments')
                                ->fileAttachmentsVisibility('public')
                                ->columnSpanFull(),
                        ])
                        ->columns(2),

                    Section::make('Botón de acción')
                        ->description('Opcional. Lleva a la persona a una página relacionada.')
                        ->icon(Heroicon::OutlinedCursorArrowRays)
                        ->schema([
                            TextInput::make('cta_label')
                                ->label('Texto del botón')
                                ->placeholder('Conocer más'),
                            TextInput::make('cta_url')
                                ->label('Destino del botón')
                                ->url()
                                ->placeholder('https://...'),
                        ])
                        ->columns(2)
                        ->collapsible(),
                ])
                    ->columnSpan(['lg' => 2]),

                Group::make([
                    Section::make('Estado')
                        ->icon(Heroicon::OutlinedCloudArrowUp)
                        ->description(function (string $operation, ?Announcement $record, $livewire): string {
                            if ($operation !== 'edit' || $record?->status !== Announcement::STATUS_DRAFT) {
                                return 'Usa Publicar o Despublicar en la parte superior.';
                            }

                            return filled($livewire->lastAutosavedAt ?? null)
                                ? "Borrador protegido. Último guardado automático: {$livewire->lastAutosavedAt}."
                                : 'Borrador protegido. Se guarda automáticamente cada 10 segundos.';
                        })
                        ->schema([
                            Select::make('status')
                                ->hiddenLabel()
                                ->options([
                                    Announcement::STATUS_DRAFT => 'Borrador',
                                    Announcement::STATUS_PUBLISHED => 'Publicado',
                                ])
                                ->default(Announcement::STATUS_DRAFT)
                                ->disabled()
                                ->dehydrated(false)
                                ->native(false),
                            Toggle::make('is_pinned')
                                ->label('Destacar en inicio')
                                ->default(false),
                        ])
                        ->extraAttributes(fn (string $operation, ?Announcement $record): array => $operation === 'edit' && $record?->status === Announcement::STATUS_DRAFT
                            ? [
                                'x-data' => '{}',
                                'x-init' => 'const autosaveTimer = setInterval(() => $wire.autosaveDraft(), 10000); $cleanup(() => clearInterval(autosaveTimer))',
                            ]
                            : []),

                    Section::make('Portada')
                        ->icon(Heroicon::OutlinedPhoto)
                        ->schema([
                            FileUpload::make('cover_image')
                                ->hiddenLabel()
                                ->disk('public')
                                ->directory('announcements/covers')
                                ->image()
                                ->imageEditor()
                                ->panelAspectRatio('16:9')
                                ->maxSize(10240)
                                ->helperText('Imagen horizontal, máximo 10 MB.'),
                        ]),

                    Section::make('Audiencia y vigencia')
                        ->description('Limita cuándo se muestra sin tener que retirarlo a mano.')
                        ->icon(Heroicon::OutlinedCalendarDays)
                        ->schema([
                            Select::make('audience')
                                ->label('¿Quién lo ve?')
                                ->options([
                                    'all' => 'Todas las personas',
                                    'authenticated' => 'Usuarios registrados',
                                ])
                                ->default('all')
                                ->required()
                                ->native(false),
                            DateTimePicker::make('starts_at')
                                ->label('Mostrar desde')
                                ->placeholder('De inmediato')
                                ->native(false)
                                ->seconds(false),
                            DateTimePicker::make('ends_at')
                                ->label('Mostrar hasta')
                                ->placeholder('Sin fecha límite')
                                ->native(false)
                                ->seconds(false)
                                ->after('starts_at'),
                        ]),
                ])
                    ->columnSpan(['lg' => 1]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('cover_image')->label('Portada')->disk('public')->square(),
                TextColumn::make('title')->label('Título')->searchable()->sortable()->limit(60),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === Announcement::STATUS_PUBLISHED ? 'Publicado' : 'Borrador')
                    ->color(fn (string $state): string => $state === Announcement::STATUS_PUBLISHED ? 'success' : 'warning'),
                IconColumn::make('is_pinned')->label('Destacado')->boolean(),
                TextColumn::make('starts_at')->label('Desde')->dateTime('d/m/Y H:i')->placeholder('Inmediato')->sortable(),
                TextColumn::make('ends_at')->label('Hasta')->dateTime('d/m/Y H:i')->placeholder('Sin límite')->sortable(),
                TextColumn::make('author.full_name')->label('Autor')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    Announcement::STATUS_DRAFT => 'Borrador',
                    Announcement::STATUS_PUBLISHED => 'Publicado',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'edit' => EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
