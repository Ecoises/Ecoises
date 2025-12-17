<?php

namespace App\Filament\Resources\Courses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                Textarea::make('thumbnail_url')
                    ->columnSpanFull(),
                Select::make('difficulty_level')
                    ->options(['principiante' => 'Principiante', 'intermedio' => 'Intermedio', 'avanzado' => 'Avanzado'])
                    ->default('principiante')
                    ->required(),
                TextInput::make('category'),
                TextInput::make('estimated_duration')
                    ->numeric(),
                TextInput::make('completion_points')
                    ->required()
                    ->numeric()
                    ->default(100),
                Select::make('achievement_id')
                    ->relationship('achievement', 'name'),
                TextInput::make('related_taxa'),
                TextInput::make('target_location_ids'),
                TextInput::make('references'),
                Select::make('author_id')
                    ->relationship('author', 'id'),
                Toggle::make('is_published')
                    ->required(),
                TextInput::make('enrollment_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('completion_rate')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('rating_average')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('rating_count')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
