<?php

namespace App\Filament\Resources\MonthlyClosures\Schemas;

use App\Models\MonthlyClosure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class MonthlyClosureForm
{
    public static function configure(Schema $schema): Schema
    {
        // Obtener el último cierre registrado
        $lastClosure = MonthlyClosure::orderBy('year', 'desc')
            ->orderBy('month', 'desc')
            ->first();

        $nextMonth = Carbon::now()->month;
        $nextYear = Carbon::now()->year;

        if ($lastClosure) {
            $nextDate = Carbon::create($lastClosure->year, $lastClosure->month, 1)->addMonth();
            $nextMonth = $nextDate->month;
            $nextYear = $nextDate->year;
        }

        return $schema
            ->components([
                TextInput::make('month')
                    ->label('Mes')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(12)
                    ->default($nextMonth)
                    ->helperText('Se sugiere automáticamente el siguiente mes al último cierre')
                    ->rules(fn (?MonthlyClosure $record) => [
                        Rule::unique('monthly_closures')
                            ->where(fn ($query) => $query
                                ->where('month', request()->input('data.month'))
                                ->where('year', request()->input('data.year'))
                            )
                            ->ignore($record?->id),
                    ]),

                TextInput::make('year')
                    ->label('Año')
                    ->required()
                    ->numeric()
                    ->default($nextYear)
                    ->helperText('Se sugiere automáticamente el siguiente año'),

                Toggle::make('is_closed')
                    ->label('¿Cerrar este mes?')
                    ->default(false)
                    ->required(),

                DateTimePicker::make('closed_at')
                    ->label('Fecha de Cierre')
                    ->disabled()
                    ->visible(fn ($record) => $record?->is_closed),
            ]);
    }
}
