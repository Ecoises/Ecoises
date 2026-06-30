<?php

namespace App\Filament\Resources\Taxas\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TaxaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('scientific_name')
                    ->required(),
                TextInput::make('common_name'),
                TextInput::make('kingdom'),
                TextInput::make('phylum'),
                TextInput::make('class'),
                TextInput::make('order_name'),
                TextInput::make('family'),
                TextInput::make('genus'),
                TextInput::make('species'),
                Select::make('conservation_status')
                    ->options([
            'LC' => 'L c',
            'NT' => 'N t',
            'VU' => 'V u',
            'EN' => 'E n',
            'CR' => 'C r',
            'EW' => 'E w',
            'EX' => 'E x',
            'DD' => 'D d',
            'NE' => 'N e',
        ]),
                Toggle::make('is_native'),
                Toggle::make('is_endemic'),
                TextInput::make('taxon_author'),
                TextInput::make('inventory_author'),
                TextInput::make('local_records_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Textarea::make('attribution')
                    ->columnSpanFull(),
                TextInput::make('observation_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('identification_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('last_observed_at'),
            ]);
    }
}
