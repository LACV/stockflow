<?php

namespace App\Filament\Resources\Types\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Toggle::make('is_active')
                    ->default(true)
                    ->required()
                    ->hiddenOn('create'),
            ]);
    }
}
