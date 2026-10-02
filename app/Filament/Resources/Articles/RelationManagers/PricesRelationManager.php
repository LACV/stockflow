<?php

namespace App\Filament\Resources\Articles\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Historial de Costos / Precios';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Components\DatePicker::make('date')
                    ->label('Fecha')
                    ->required(),
                Components\TextInput::make('price')
                    ->label('Valor (Costo/Precio)')
                    ->numeric()
                    ->prefix('$')
                    ->required(),
                Components\TextInput::make('quantity')
                    ->label('Cantidad Registrada')
                    ->numeric()
                    ->required(),
                Components\Select::make('source')
                    ->label('Origen')
                    ->options([
                        'MANUAL' => 'Manual',
                        'ENTRY' => 'Entrada',
                        'EXIT' => 'Salida',
                        'ADJUSTMENT' => 'Ajuste',
                    ])
                    ->default('MANUAL'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('price')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Fecha')
                    ->date('d/m/y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('Origen')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'ENTRY' => 'Entrada',
                        'EXIT' => 'Salida',
                        'ADJUSTMENT' => 'Ajuste',
                        default => 'Manual',
                    })
                    ->color(fn ($state) => match ($state) {
                        'ENTRY' => 'success',
                        'EXIT' => 'danger',
                        'ADJUSTMENT' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.'))
                    ->suffix(' und')
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('price')
                    ->label('Costo / Precio')
                    ->money('COP', true)
                    ->sortable()
                    ->alignEnd(),
            ])
            ->filters([])
            ->headerActions([
                CreateAction::make()
                    ->label('Agregar registro manual')
                    ->mutateDataUsing(function (array $data): array {
                        $data['source'] = $data['source'] ?? 'MANUAL';

                        return $data;
                    }),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([]);
    }
}
