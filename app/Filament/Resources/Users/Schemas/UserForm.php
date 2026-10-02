<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificación')
                    ->description('Datos del usuario')
                    ->icon('heroicon-o-identification')
                    ->collapsible()
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Correo electrónico')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                    ])->columns(2)->columnSpanFull(),

                Section::make('Contraseña')
                    ->description(fn ($livewire) => $livewire instanceof EditRecord ? 'Dejar en blanco para mantener la actual' : null)
                    ->icon('heroicon-o-lock-closed')
                    ->collapsible()
                    ->collapsed(fn ($livewire) => $livewire instanceof EditRecord)
                    ->schema([
                        TextInput::make('password')
                            ->label('Contraseña')
                            ->password()
                            ->confirmed()
                            ->required(fn ($livewire) => $livewire instanceof CreateRecord)
                            ->maxLength(255),
                        TextInput::make('password_confirmation')
                            ->label('Confirmar contraseña')
                            ->password()
                            ->required(fn ($livewire) => $livewire instanceof CreateRecord)
                            ->maxLength(255),
                    ])->columns(2)->columnSpanFull(),

                Section::make('Estado')
                    ->description('Configuración de la cuenta')
                    ->icon('heroicon-o-check-circle')
                    ->collapsible()
                    ->schema([
                        Select::make('roles')
                            ->label('Rol')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload()
                            ->visible(fn ($livewire) => $livewire instanceof CreateRecord || $livewire instanceof EditRecord),
                        DateTimePicker::make('email_verified_at')
                            ->label('Correo verificado')
                            ->native(false)
                            ->displayFormat('d/m/Y H:i'),
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->required(),
                    ])->columns(2)->columnSpanFull(),
            ]);
    }
}
