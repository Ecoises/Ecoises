<?php

namespace App\Filament\Resources\Reports;

use App\Filament\Resources\Reports\Pages\EditReport;
use App\Filament\Resources\Reports\Pages\ListReports;
use App\Models\Report;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Moderación';

    protected static ?string $navigationLabel = 'Reportes y feedback';

    protected static ?string $modelLabel = 'Caso de moderación';

    protected static ?string $pluralModelLabel = 'Reportes y feedback';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = Report::open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Información recibida')
                ->description('El mensaje original se conserva sin modificaciones para mantener la trazabilidad.')
                ->schema([
                    TextInput::make('type')
                        ->label('Tipo')
                        ->formatStateUsing(fn (?string $state): string => Report::getTypes()[$state] ?? 'Reporte')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('category')
                        ->label('Categoría')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('subject')
                        ->label('Asunto o contenido')
                        ->placeholder('Sin asunto')
                        ->disabled()
                        ->dehydrated(false),
                    TextInput::make('user.full_name')
                        ->label('Enviado por')
                        ->disabled()
                        ->dehydrated(false),
                    Textarea::make('comment')
                        ->label('Mensaje')
                        ->rows(6)
                        ->columnSpanFull()
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2),

            Section::make('Gestión del caso')
                ->schema([
                    Select::make('status')
                        ->label('Estado')
                        ->options(Report::getStatuses())
                        ->required()
                        ->native(false),
                    Select::make('priority')
                        ->label('Prioridad')
                        ->options(Report::getPriorities())
                        ->required()
                        ->native(false),
                    Select::make('assigned_to')
                        ->label('Responsable')
                        ->relationship('assignedTo', 'full_name')
                        ->searchable()
                        ->preload()
                        ->native(false),
                    Textarea::make('resolution_notes')
                        ->label('Notas internas y resolución')
                        ->rows(5)
                        ->columnSpanFull()
                        ->helperText('Estas notas son internas y no se muestran al usuario.'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->columns([
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Report::getTypes()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Report::TYPE_OBSERVATION => 'danger',
                        Report::TYPE_CONTENT_FEEDBACK => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('subject')
                    ->label('Asunto')
                    ->placeholder(fn (Report $record): string => $record->observation_id
                        ? "Observación #{$record->observation_id}"
                        : 'Sin asunto')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('comment')
                    ->label('Mensaje')
                    ->limit(65)
                    ->tooltip(fn (Report $record): string => $record->comment)
                    ->searchable(),
                TextColumn::make('user.full_name')
                    ->label('Usuario')
                    ->placeholder('Usuario eliminado')
                    ->searchable(),
                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Report::getPriorities()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Report::PRIORITY_URGENT => 'danger',
                        Report::PRIORITY_HIGH => 'warning',
                        Report::PRIORITY_LOW => 'gray',
                        default => 'info',
                    })
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Report::getStatuses()[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Report::STATUS_PENDING => 'danger',
                        Report::STATUS_IN_REVIEW => 'warning',
                        Report::STATUS_RESOLVED => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('assignedTo.full_name')
                    ->label('Responsable')
                    ->placeholder('Sin asignar')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Recibido')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(Report::getTypes()),
                SelectFilter::make('status')->label('Estado')->options(Report::getStatuses()),
                SelectFilter::make('priority')->label('Prioridad')->options(Report::getPriorities()),
                SelectFilter::make('assigned_to')->label('Responsable')->relationship('assignedTo', 'full_name'),
            ])
            ->recordActions([
                EditAction::make()->label('Gestionar'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'assignedTo', 'reportable']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReports::route('/'),
            'edit' => EditReport::route('/{record}/edit'),
        ];
    }
}
