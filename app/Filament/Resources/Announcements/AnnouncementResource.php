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
        return $schema->components([
            Section::make('Borrador protegido')
                ->description(fn ($livewire): string => filled($livewire->lastAutosavedAt ?? null)
                    ? "Último guardado automático: {$livewire->lastAutosavedAt}."
                    : 'Los cambios se guardan automáticamente cada 10 segundos.')
                ->icon('heroicon-o-cloud-arrow-up')
                ->schema([])
                ->visible(fn (string $operation, ?Announcement $record): bool => $operation === 'edit' && $record?->status === Announcement::STATUS_DRAFT)
                ->extraAttributes([
                    'x-data' => '{}',
                    'x-init' => 'const autosaveTimer = setInterval(() => $wire.autosaveDraft(), 10000); $cleanup(() => clearInterval(autosaveTimer))',
                ]),

            Section::make('Publicación')
                ->description('El anuncio permanece como borrador hasta que elijas Publicado. Puedes limitar su vigencia sin retirarlo manualmente.')
                ->schema([
                    TextInput::make('title')
                        ->label('Título')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('slug', Str::slug($state ?? ''))),
                    TextInput::make('slug')
                        ->required()
                        ->unique('announcements', 'slug', ignoreRecord: true)
                        ->readOnly(),
                    Hidden::make('author_id')->default(fn () => auth()->id()),
                    Textarea::make('summary')
                        ->label('Resumen breve')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull(),
                    RichEditor::make('body')
                        ->label('Contenido')
                        ->fileAttachmentsDisk('public')
                        ->fileAttachmentsDirectory('announcements/attachments')
                        ->fileAttachmentsVisibility('public')
                        ->columnSpanFull(),
                    FileUpload::make('cover_image')
                        ->label('Imagen de portada')
                        ->disk('public')
                        ->directory('announcements/covers')
                        ->image()
                        ->imageEditor()
                        ->maxSize(10240),
                    Select::make('audience')
                        ->label('Audiencia')
                        ->options([
                            'all' => 'Todas las personas',
                            'authenticated' => 'Usuarios registrados',
                        ])
                        ->default('all')
                        ->required()
                        ->native(false),
                ])
                ->columns(2),

            Section::make('Acción y vigencia')
                ->schema([
                    TextInput::make('cta_label')
                        ->label('Texto del botón')
                        ->placeholder('Conocer más'),
                    TextInput::make('cta_url')
                        ->label('Destino del botón')
                        ->url()
                        ->placeholder('https://...'),
                    DateTimePicker::make('starts_at')
                        ->label('Mostrar desde')
                        ->seconds(false),
                    DateTimePicker::make('ends_at')
                        ->label('Mostrar hasta')
                        ->seconds(false)
                        ->after('starts_at'),
                    Toggle::make('is_pinned')
                        ->label('Destacar en inicio')
                        ->default(false),
                    Select::make('status')
                        ->label('Estado')
                        ->options([
                            Announcement::STATUS_DRAFT => 'Borrador',
                            Announcement::STATUS_PUBLISHED => 'Publicado',
                        ])
                        ->default(Announcement::STATUS_DRAFT)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Usa las acciones Publicar o Despublicar en la parte superior.')
                        ->native(false),
                ])
                ->columns(2),
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
