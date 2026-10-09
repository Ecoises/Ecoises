<?php

namespace App\Filament\Resources\Challenges;

use App\Filament\Resources\Challenges\Pages\CreateChallenge;
use App\Filament\Resources\Challenges\Pages\EditChallenge;
use App\Filament\Resources\Challenges\Pages\ListChallenges;
use App\Models\Challenge;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Retos de campo: el administrador elige una especie, cuántos avistamientos
 * se necesitan y cuántos puntos da completarlo.
 */
class ChallengeResource extends Resource
{
    protected static ?string $model = Challenge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Gamificación';

    protected static ?string $navigationLabel = 'Retos de campo';

    protected static ?string $modelLabel = 'Reto';

    protected static ?string $pluralModelLabel = 'Retos de campo';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Reto')
                ->description('Ejemplo: "Si observas un asoma terciopelo y subes la foto, ganas 100 puntos".')
                ->schema([
                    TextInput::make('title')
                        ->label('Título')
                        ->required()
                        ->maxLength(255),
                    Select::make('taxon_id')
                        ->label('Especie a observar')
                        ->relationship('taxon', 'scientific_name')
                        ->getOptionLabelFromRecordUsing(fn ($record): string => trim("{$record->common_name} ({$record->scientific_name})"))
                        ->searchable(['scientific_name', 'common_name'])
                        ->helperText('Déjalo vacío para aceptar cualquier especie.'),
                    Textarea::make('description')
                        ->label('Instrucciones para el participante')
                        ->rows(3)
                        ->columnSpanFull(),
                    Hidden::make('challenge_type')->default(Challenge::TYPE_OBSERVATION),
                ])
                ->columns(2),

            Section::make('Recompensa y reglas')
                ->schema([
                    TextInput::make('target_value')
                        ->label('Avistamientos necesarios')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    TextInput::make('reward_points')
                        ->label('Puntos al completarlo')
                        ->numeric()
                        ->minValue(0)
                        ->default(50)
                        ->required(),
                    Select::make('reward_badge_id')
                        ->label('Insignia (opcional)')
                        ->relationship('rewardBadge', 'name')
                        ->searchable()
                        ->preload(),
                    Toggle::make('requires_photo')
                        ->label('Exigir foto como evidencia')
                        ->default(true),
                ])
                ->columns(2),

            Section::make('Vigencia')
                ->schema([
                    DateTimePicker::make('start_date')->label('Desde')->seconds(false),
                    DateTimePicker::make('end_date')->label('Hasta')->seconds(false)->after('start_date'),
                    Toggle::make('is_active')->label('Activo')->default(true),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Reto')->searchable()->limit(50),
                TextColumn::make('taxon.common_name')
                    ->label('Especie')
                    ->description(fn (Challenge $record): ?string => $record->taxon?->scientific_name)
                    ->placeholder('Cualquiera'),
                TextColumn::make('target_value')->label('Meta')->suffix(' avist.'),
                TextColumn::make('reward_points')->label('Puntos')->badge()->color('success'),
                TextColumn::make('participant_count')->label('Participantes')->numeric(),
                TextColumn::make('end_date')->label('Hasta')->dateTime('d/m/Y')->placeholder('Sin límite')->sortable(),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChallenges::route('/'),
            'create' => CreateChallenge::route('/create'),
            'edit' => EditChallenge::route('/{record}/edit'),
        ];
    }
}
