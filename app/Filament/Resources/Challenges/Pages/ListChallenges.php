<?php

namespace App\Filament\Resources\Challenges\Pages;

use App\Filament\Resources\Challenges\ChallengeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListChallenges extends ListRecords
{
    protected static string $resource = ChallengeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
