<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class UserChart extends ChartWidget
{
    protected ?string $heading = 'Usuarios Registrados';

    protected static ?int $sort = 3;

    protected ?string $description = 'Nuevas cuentas por mes.';

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return auth()->user()?->can('View:SuperAdminDashboard') ?? false;
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $data = \App\Models\User::query()
            ->when($activeFilter, fn ($query) => $query->whereYear('created_at', $activeFilter))
            ->selectRaw('MONTH(created_at) as month, count(*) as count')
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        $dataset = [];
        // Fill 12 months with 0 if no data
        for ($i = 1; $i <= 12; $i++) {
            $dataset[] = $data[$i] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Usuarios Registrados',
                    'data' => $dataset,
                    'fill' => true,
                    'tension' => 0.35,
                ],
            ],
            'labels' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'suggestedMax' => 5, 'ticks' => ['precision' => 0]]],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        // Get all years where users were created
        $years = \App\Models\User::query()
            ->selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year', 'year')
            ->toArray();

        // Ensure current year is always available even if no users
        $currentYear = date('Y');
        if (! isset($years[$currentYear])) {
            $years[$currentYear] = $currentYear;
        }

        // Sort keys desc just in case
        krsort($years);

        return $years;
    }

    public ?string $filter = null; // Will default to first item in getFilters logic or we can set it explicitly

    public function mount(): void
    {
        // Set default filter to current year
        $this->filter = (string) now()->year;
    }
}
