<?php

namespace App\Filament\Widgets;

use App\Models\EducationalContent;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

class CreatedContents extends ChartWidget
{
    protected ?string $heading = 'Contenidos Creados';

    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user?->can('View:SuperAdminDashboard')
            || $user?->can('View:EditorialDashboard')
            || $user?->can('View:EducatorDashboard');
    }

    protected function getData(): array
    {
        $activeFilter = $this->filter;

        $data = $this->contentQuery()
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
        $years = $this->contentQuery()
            ->selectRaw('YEAR(created_at) as year')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year', 'year')
            ->toArray();

        // Ensure current year is always available
        $currentYear = date('Y');
        if (! isset($years[$currentYear])) {
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

    private function contentQuery(): Builder
    {
        return EducationalContent::query()
            ->when(
                auth()->user()?->hasRole('educador'),
                fn (Builder $query): Builder => $query->where('author_id', auth()->id()),
            );
    }
}
