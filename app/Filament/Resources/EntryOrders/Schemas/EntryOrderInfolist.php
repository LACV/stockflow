<?php

namespace App\Filament\Resources\EntryOrders\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EntryOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('Estado del Documento')
                    ->icon('heroicon-o-document-check')
                    ->columns(3)
                    ->schema([

                        TextEntry::make('type')
                            ->label('Tipo')
                            ->badge()
                            ->color('primary')
                            ->formatStateUsing(fn ($state) => strtoupper($state)),
                    ]),

                TextEntry::make('transaction_date')
                    ->label('Fecha de Recepción')
                    ->icon('heroicon-o-calendar-days')
                    ->date(),

                TextEntry::make('observations')
                    ->label('Observaciones')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('provider.provider')
                    ->label('Proveedor')
                    ->placeholder('-'),
                TextEntry::make('invoice_number')
                    ->label('N° Factura')
                    ->icon('heroicon-o-receipt-percent')
                    ->placeholder('-')
                    ->copyable()
                    ->copyMessage('Número copiado'),

                TextEntry::make('purchase_order')
                    ->label('Orden de Compra')
                    ->placeholder('-')
                    ->copyable()
                    ->copyMessage('N° OC copiado'),

                TextEntry::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'inactive' => 'warning',
                        'active' => 'success',
                        'annulled' => 'danger',
                        default => 'warning',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'inactive' => 'heroicon-o-clock',
                        'active' => 'heroicon-o-check-circle',
                        'annulled' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-clock',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {

                        'inactive' => 'Inactiva',
                        'active' => 'Activa',
                        'annulled' => 'Anulada',
                        default => ucfirst($state),
                    }),
                TextEntry::make('month')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('year')
                    ->numeric()
                    ->placeholder('-'),
            ]);
    }
}
