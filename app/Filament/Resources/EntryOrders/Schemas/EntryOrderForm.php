<?php

namespace App\Filament\Resources\EntryOrders\Schemas;

use App\Filament\Support\MonthHelper;
use App\Models\MonthlyClosure;
use App\Models\Provider;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EntryOrderForm
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

        $isDisabled = fn ($livewire) => $livewire instanceof EditRecord && $livewire->record?->status === 'active';

        return $schema
            ->components([
                Section::make('Información del Documento')
                    ->description('Datos de la factura y proveedor')
                    ->collapsible()
                    ->collapsed(fn ($livewire) => $livewire instanceof EditRecord)
                    ->schema([
                        Select::make('provider_id')
                            ->label('Proveedor')
                            ->relationship('provider', 'provider', modifyQueryUsing: fn ($query) => $query->where('status', true))
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled($isDisabled)
                            ->createOptionForm([
                                TextInput::make('provider')
                                    ->label('Nombre / Razón social')
                                    ->required()
                                    ->maxLength(150),
                                TextInput::make('identification')
                                    ->label('NIT')
                                    ->maxLength(20),
                                TextInput::make('phone')
                                    ->label('Teléfono')
                                    ->tel()
                                    ->maxLength(15),
                                TextInput::make('email')
                                    ->label('Correo electrónico')
                                    ->email()
                                    ->maxLength(80),
                                TextInput::make('address')
                                    ->label('Dirección')
                                    ->maxLength(80),
                            ])
                            ->createOptionUsing(fn (array $data): int => Provider::create([...$data, 'status' => true])->getKey())
                            ->columnSpan(2),
                        DatePicker::make('transaction_date')
                            ->label('Fecha de Recepción')
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
                            ->label('N° Factura')
                            ->required()
                            ->disabled($isDisabled),
                        TextInput::make('entry_number')
                            ->label('N° Entrada')
                            ->nullable()
                            ->disabled($isDisabled),
                        TextInput::make('purchase_order')
                            ->label('N° O.C.')
                            ->nullable()
                            ->disabled($isDisabled),

                        Select::make('status')
                            ->label('Estado')
                            ->options(['inactive' => 'Inactiva', 'active' => 'Activa', 'annulled' => 'Anulada'])
                            ->default('inactive')
                            ->disabled()
                            ->hidden(fn ($livewire) => $livewire instanceof CreateRecord)
                            ->required(),
                        Textarea::make('observations')
                            ->label('Observaciones')
                            ->columnSpanFull()
                            ->disabled($isDisabled),
                    ])->columns(3)->columnSpanFull(),

            ]);
    }
}
