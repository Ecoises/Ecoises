<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos')
                ->icon('heroicon-m-users'),
            'admins' => Tab::make('Administrativos')
                ->icon('heroicon-m-shield-check')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('roles', function ($query) {
                    $query->whereIn('name', ['super_admin', 'editor', 'educador']);
                })),
            'users' => Tab::make('Usuarios')
                ->icon('heroicon-m-user')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas('roles', function ($query) {
                    $query->where('name', 'panel_user');
                })->orWhereDoesntHave('roles')),
        ];
    }
}
