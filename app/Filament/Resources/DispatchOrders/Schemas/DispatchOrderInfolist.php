<?php

namespace App\Filament\Resources\DispatchOrders\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DispatchOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('type')
                    ->badge(),
                TextEntry::make('transaction_date')
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
                    ->placeholder('-'),
                TextEntry::make('purchase_order')
                    ->placeholder('-'),
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
