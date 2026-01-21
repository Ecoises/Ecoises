<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class CreatedContents extends ChartWidget
{
    protected ?string $heading = 'Contenidos Creados';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $data = \App\Models\EducationalContent::query()
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
                    'label' => 'Contenidos Creados',
                    'data' => $dataset,
                ],
            ],
            'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        // Get all years where content was created
        $years = \App\Models\EducationalContent::query()
            ->selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year', 'year')
            ->toArray();

        // Ensure current year is always available
        $currentYear = date('Y');
        if (!isset($years[$currentYear])) {
             $years[$currentYear] = $currentYear;
        }
        
        krsort($years);

        return $years;
    }

    public ?string $filter = null;

    public function mount(): void 
    {
        $this->filter = (string) now()->year;
    }
}
