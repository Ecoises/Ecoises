<?php

namespace App\Filament\Resources\Taxas\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class TaxaInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('scientific_name'),
                TextEntry::make('common_name')
                    ->placeholder('-'),
                TextEntry::make('kingdom')
                    ->placeholder('-'),
                TextEntry::make('phylum')
                    ->placeholder('-'),
                TextEntry::make('class')
                    ->placeholder('-'),
                TextEntry::make('order_name')
                    ->placeholder('-'),
                TextEntry::make('family')
                    ->placeholder('-'),
                TextEntry::make('genus')
                    ->placeholder('-'),
                TextEntry::make('species')
                    ->placeholder('-'),
                TextEntry::make('conservation_status')
                    ->badge()
                    ->placeholder('-'),
                IconEntry::make('is_native')
                    ->boolean()
                    ->placeholder('-'),
                IconEntry::make('is_endemic')
                    ->boolean()
                    ->placeholder('-'),
                TextEntry::make('taxon_author')
                    ->placeholder('-'),
                TextEntry::make('inventory_author')
                    ->placeholder('-'),
                TextEntry::make('local_records_count')
                    ->numeric(),
                TextEntry::make('attribution')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('observation_count')
                    ->numeric(),
                TextEntry::make('identification_count')
                    ->numeric(),
                TextEntry::make('last_observed_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
