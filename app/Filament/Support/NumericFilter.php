<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class NumericFilter
{
    protected static array $operators = [
        '>' => 'Mayor a',
        '>=' => 'Mayor o igual a',
        '=' => 'Igual a',
        '<' => 'Menor a',
        '<=' => 'Menor o igual a',
    ];

    public static function make(string $name, string $column, string $label): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->columns(2)
            ->schema([
                Select::make('operator')
                    ->label('Operador')
                    ->options(static::$operators)
                    ->default('>')
                    ->required(),
                TextInput::make('value')
                    ->label('Valor')
                    ->numeric()
                    ->required(),
            ])
            ->query(function (Builder $query, array $data) use ($column) {
                $operator = $data['operator'] ?? null;
                $value = $data['value'] ?? null;

                if (! in_array($operator, array_keys(static::$operators), true) || ! is_numeric($value)) {
                    return;
                }

                $query->where($column, $operator, $value);
            });
    }
}
