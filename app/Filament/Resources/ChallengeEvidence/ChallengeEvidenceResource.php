<?php

namespace App\Filament\Resources\ChallengeEvidence;

use App\Filament\Resources\ChallengeEvidence\Pages\ListChallengeEvidence;
use App\Models\Observation;
use App\Services\Gamification\ChallengeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Revisión de avistamientos enviados como evidencia de retos.
 * Se aprueban solos al enviarse; aquí un moderador puede rechazarlos
 * (y se revierten los puntos) o volver a aprobarlos.
 */
class ChallengeEvidenceResource extends Resource
{
    protected static ?string $model = Observation::class;

    protected static ?string $slug = 'challenge-evidence';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Gamificación';

    protected static ?string $navigationLabel = 'Evidencias de retos';

    protected static ?string $modelLabel = 'Evidencia';

    protected static ?string $pluralModelLabel = 'Evidencias de retos';

    protected static ?int $navigationSort = 2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('challenge_id')
            ->with(['user:id,full_name', 'taxon:id,scientific_name,common_name', 'challenge:id,title', 'photos']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photo')
                    ->label('Foto')
                    ->state(fn (Observation $record): ?string => ($url = $record->photos->first()?->photo_url) ? url($url) : null)
                    ->square(),
                TextColumn::make('challenge.title')->label('Reto')->limit(40)->searchable(),
                TextColumn::make('taxon.common_name')
                    ->label('Especie')
                    ->description(fn (Observation $record): ?string => $record->taxon?->scientific_name),
                TextColumn::make('user.full_name')->label('Participante')->searchable(),
                TextColumn::make('observed_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('verification_status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::statusLabels()[$state] ?? '—')
                    ->color(fn (?string $state): string => match ($state) {
                        Observation::STATUS_APPROVED => 'success',
                        Observation::STATUS_REJECTED => 'danger',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('verification_status')->label('Estado')->options(self::statusLabels()),
                SelectFilter::make('challenge_id')->label('Reto')->relationship('challenge', 'title'),
            ])
            ->recordActions([
                Action::make('reject')
                    ->label('Rechazar')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Observation $record): bool => $record->verification_status !== Observation::STATUS_REJECTED)
                    ->schema([
                        Textarea::make('note')->label('Motivo (lo verá el participante)')->required(),
                    ])
                    ->action(function (Observation $record, array $data): void {
                        app(ChallengeService::class)->reject($record, auth()->user(), $data['note']);
                        Notification::make()->title('Evidencia rechazada y puntos revertidos')->success()->send();
                    }),
                Action::make('approve')
                    ->label('Aprobar')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Observation $record): bool => $record->verification_status !== Observation::STATUS_APPROVED)
                    ->requiresConfirmation()
                    ->action(function (Observation $record): void {
                        app(ChallengeService::class)->approve($record, auth()->user());
                        Notification::make()->title('Evidencia aprobada')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChallengeEvidence::route('/'),
        ];
    }

    private static function statusLabels(): array
    {
        return [
            Observation::STATUS_PENDING => 'Pendiente',
            Observation::STATUS_APPROVED => 'Aprobada',
            Observation::STATUS_REJECTED => 'Rechazada',
        ];
    }
}
