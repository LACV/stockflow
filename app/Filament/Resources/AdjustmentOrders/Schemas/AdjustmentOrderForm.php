<?php

namespace App\Filament\Resources\AdjustmentOrders\Schemas;

use App\Filament\Support\MonthHelper;
use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AdjustmentOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        $openMonth = MonthlyClosure::where('is_closed', false)->first();
        $minDate = null;
        $maxDate = null;
        $defaultDate = now();
        $isSuperAdmin = auth()->user()?->can('Reopen:MonthlyClosure');
        $hasReopenedMonths = $isSuperAdmin && MonthlyClosure::where('is_closed', true)->whereNotNull('reopened_at')->exists();

        if ($openMonth) {
            $firstDay = Carbon::create($openMonth->year, $openMonth->month, 1)->startOfDay();
            $lastDay = $firstDay->copy()->endOfMonth()->endOfDay();

            if (! $hasReopenedMonths) {
                $minDate = $firstDay;
                $maxDate = $lastDay;
            }

            if (now()->lessThan($firstDay) || now()->greaterThan($lastDay)) {
                $defaultDate = $firstDay;
            }
        }

        return $schema
            ->components([
                Grid::make(1)->schema([
                    Section::make('Boleta de Ajuste')
                        ->collapsible()
                        ->collapsed(fn ($livewire) => $livewire instanceof EditRecord)
                        ->schema([
                            DatePicker::make('transaction_date')
                                ->label('Fecha del Ajuste')
                                ->default($defaultDate)
                                ->minDate($minDate)
                                ->maxDate($maxDate)
                                ->required()
                                ->afterStateUpdated(function ($state, $set, $get) {
                                    if ($state && MonthHelper::isMonthClosed($state)) {
                                        Notification::make()
                                            ->warning()
                                            ->title('Mes cerrado')
                                            ->body('El mes seleccionado está cerrado. No se pueden registrar movimientos en este período.')
                                            ->send();
                                    }
                                })
                                ->reactive()
                                ->disabled(function ($state, $livewire) {
                                    if ($livewire instanceof EditRecord && $livewire->record?->status === 'active') {
                                        return true;
                                    }
                                    if ($state && MonthHelper::isMonthClosed($state)) {
                                        return true;
                                    }

                                    return false;
                                }),
                            TextInput::make('invoice_number')
                                ->label('Folio Físico / Documento de Respaldo')
                                ->nullable(),
                            Textarea::make('observations')
                                ->label('Justificación')
                                ->columnSpanFull()
                                ->disabled(fn ($livewire) => $livewire instanceof EditRecord && $livewire->record?->status === 'active'),
                        ])->columns(2),
                ]),
            ]);
    }
}
