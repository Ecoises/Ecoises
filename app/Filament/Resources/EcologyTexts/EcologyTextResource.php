<?php

namespace App\Filament\Resources\EcologyTexts;

use App\Filament\Resources\EcologyTexts\Pages\EditEcologyText;
use App\Filament\Resources\EcologyTexts\Pages\ListEcologyTexts;
use App\Jobs\GenerateEcologyTextJob;
use App\Models\SpeciesEcologyText;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Revisión de los textos de importancia ecológica redactados con IA.
 * Se publican con la etiqueta "Redactado con IA"; aquí un experto los marca
 * como revisados, los corrige, los oculta o pide redactarlos de nuevo.
 */
class EcologyTextResource extends Resource
{
    protected static ?string $model = SpeciesEcologyText::class;

    protected static ?string $slug = 'ecology-texts';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Biodiversidad';

    protected static ?string $navigationLabel = 'Importancia ecológica';

    protected static ?string $modelLabel = 'Texto ecológico';

    protected static ?string $pluralModelLabel = 'Textos ecológicos';

    public static function getNavigationBadge(): ?string
    {
        $pending = SpeciesEcologyText::where('status', SpeciesEcologyText::STATUS_PUBLISHED)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Textos publicados sin revisión';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('taxon:id,scientific_name,common_name');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Texto')
                ->description('Corrige lo que haga falta. Al guardar como "Revisado" se quita la etiqueta de IA en la ficha.')
                ->schema([
                    Placeholder::make('especie')
                        ->label('Especie')
                        ->content(fn (?SpeciesEcologyText $record): string => trim(($record?->taxon?->common_name ?? '').' ('.($record?->taxon?->scientific_name ?? '').')')),
                    Placeholder::make('fuentes')
                        ->label('Fuentes usadas')
                        ->content(fn (?SpeciesEcologyText $record): string => implode(', ', $record?->sources ?? []) ?: '—'),
                    Textarea::make('text')->label('Texto')->rows(8)->required()->columnSpanFull(),
                    Select::make('status')
                        ->label('Estado')
                        ->options(self::statusLabels())
                        ->required()
                        ->native(false),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('taxon.common_name')
                    ->label('Especie')
                    ->description(fn (SpeciesEcologyText $record): ?string => $record->taxon?->scientific_name)
                    ->searchable(['common_name', 'scientific_name']),
                TextColumn::make('text')->label('Texto')->limit(90)->wrap(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        SpeciesEcologyText::STATUS_REVIEWED => 'success',
                        SpeciesEcologyText::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('generated_at')->label('Redactado')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(self::statusLabels()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Revisado')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (SpeciesEcologyText $record): bool => $record->status !== SpeciesEcologyText::STATUS_REVIEWED)
                    ->action(function (SpeciesEcologyText $record): void {
                        $record->update(['status' => SpeciesEcologyText::STATUS_REVIEWED, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
                        Notification::make()->title('Texto marcado como revisado')->success()->send();
                    }),
                Action::make('regenerate')
                    ->label('Redactar de nuevo')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->action(function (SpeciesEcologyText $record): void {
                        $taxonId = $record->taxon_id;
                        $record->delete();
                        GenerateEcologyTextJob::dispatch($taxonId);
                        Notification::make()->title('Se redactará de nuevo en unos segundos')->success()->send();
                    }),
                EditAction::make(),
            ])
            ->defaultSort('generated_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEcologyTexts::route('/'),
            'edit' => EditEcologyText::route('/{record}/edit'),
        ];
    }

    private static function statusLabels(): array
    {
        return [
            SpeciesEcologyText::STATUS_PUBLISHED => 'Publicado (IA, sin revisar)',
            SpeciesEcologyText::STATUS_REVIEWED => 'Revisado',
            SpeciesEcologyText::STATUS_REJECTED => 'Oculto',
        ];
    }
}
