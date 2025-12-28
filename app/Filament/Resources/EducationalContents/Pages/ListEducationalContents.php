<?php

namespace App\Filament\Resources\EducationalContents\Pages;

use App\Filament\Resources\EducationalContents\EducationalContentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
// Cambia el import a:
use App\Livewire\TotalContentsWidget;
use App\Livewire\PublishedContentsWidget;
use App\Livewire\TotalViewsWidget;

class ListEducationalContents extends ListRecords
{
    protected static string $resource = EducationalContentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TotalContentsWidget::class,
            PublishedContentsWidget::class,
            TotalViewsWidget::class,
        ];
    }

   public function getHeaderWidgetsColumns(): int | array 
   {
        return 3; 
    }
}
