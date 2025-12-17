<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Información Personal')
                    ->schema([
                        TextInput::make('full_name')
                            ->label('Nombre Completo')
                            ->required()
                            ->maxLength(100),
                        
                        TextInput::make('email')
                            ->label('Correo Electrónico')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        
                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->required(fn (string $context): bool => $context === 'create')
                            ->dehydrated(fn ($state) => filled($state))
                            ->revealable()
                            ->minLength(8)
                            ->maxLength(255)
                            ->helperText('Mínimo 8 caracteres. Dejar en blanco para mantener la contraseña actual.'),
                        
                        FileUpload::make('avatar')
                            ->label('Avatar')
                            ->image()
                            ->imageEditor()
                            ->directory('avatars')
                            ->maxSize(2048)
                            ->helperText('Imagen de perfil (máx. 2MB)'),
                        
                        RichEditor::make('bio')
                            ->label('Biografía')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                
                Section::make('Roles y Permisos')
                    ->schema([
                        Select::make('roles')
                            ->label('Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->helperText('Selecciona uno o más roles para este usuario'),

                        Toggle::make('is_active')
                            ->label('Usuario Activo')
                            ->default(true)
                            ->required(),
                    ]),
                    
                
                Section::make('Gamificación')
                    ->schema([
                        TextInput::make('total_score')
                            ->label('Puntuación Total')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false),
                        
                        TextInput::make('level')
                            ->label('Nivel')
                            ->numeric()
                            ->default(1)
                            ->disabled()
                            ->dehydrated(false),
                        
                        TextInput::make('experience_points')
                            ->label('Puntos de Experiencia')
                            ->numeric()
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(3)
                    ->collapsed()
                    ->hidden(function ($record) {
                        // Ocultar para usuarios con roles administrativos
                        if (!$record) {
                            return false; // Mostrar en creación
                        }
                        return $record->hasAnyRole(['super_admin', 'panel_user', 'educador', 'editor']);
                    }),
                
              
                    
            ]);
    }
}
