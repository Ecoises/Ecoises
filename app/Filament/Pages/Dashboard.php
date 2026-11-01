<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CreatedContents;
use App\Filament\Widgets\DifficultActivities;
use App\Filament\Widgets\EditorialWorkflowOverview;
use App\Filament\Widgets\EducationalAnalyticsOverview;
use App\Filament\Widgets\EducatorContentOverview;
use App\Filament\Widgets\Leaderboards;
use App\Filament\Widgets\ModerationOverview;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\UserChart;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string|Htmlable
    {
        $user = auth()->user();

        return match (true) {
            $user?->hasRole('super_admin') => 'Resumen general de Ecoises',
            $user?->hasRole('editor') => 'Panel editorial',
            $user?->hasRole('educador') => 'Mi espacio educativo',
            $user?->hasRole('moderador') => 'Panel de moderación',
            default => 'Panel administrativo',
        };
    }

    public function getColumns(): int|array
    {
        return [
            'md' => 2,
        ];
    }

    /**
     * El escritorio se agrupa por temas; cada sección solo aparece si el
     * rol actual puede ver al menos uno de sus widgets.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->widgetSection(
                'Panorama general',
                'Las cifras clave de la plataforma de un vistazo.',
                'heroicon-o-squares-2x2',
                [StatsOverview::class, EditorialWorkflowOverview::class, EducatorContentOverview::class, ModerationOverview::class],
            ),
            $this->widgetSection(
                'Aprendizaje',
                'Cómo avanzan las personas en los contenidos educativos.',
                'heroicon-o-academic-cap',
                [EducationalAnalyticsOverview::class],
            ),
            $this->widgetSection(
                'Tendencias del año',
                'Evolución mensual de contenidos y registros.',
                'heroicon-o-chart-bar',
                [CreatedContents::class, UserChart::class],
            ),
            $this->widgetSection(
                'Seguimiento',
                'Actividades con baja aprobación y participantes más activos.',
                'heroicon-o-clipboard-document-check',
                [DifficultActivities::class, Leaderboards::class],
                columns: ['md' => 1, 'xl' => 2],
            ),
        ]);
    }

    /**
     * @param  array<class-string>  $widgets
     * @param  int|array<string, int>  $columns
     */
    private function widgetSection(string $heading, string $description, string $icon, array $widgets, int|array|null $columns = null): Section
    {
        $components = $this->getWidgetsSchemaComponents($widgets);

        return Section::make($heading)
            ->description($description)
            ->icon($icon)
            ->contained(false)
            ->schema([
                Grid::make($columns ?? $this->getColumns())->schema($components),
            ])
            ->visible(filled($components));
    }
}
