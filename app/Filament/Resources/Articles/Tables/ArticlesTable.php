<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Filament\Support\ArticleSearch;
use App\Filament\Support\NumericFilter;
use App\Models\Brand;
use App\Models\Department;
use App\Models\Type;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ArticlesTable
{
    protected static array $unitLabels = [
        'UNIDAD' => 'und',
        'KILO' => 'kg',
        'LIBRA' => 'Lb',
        'GRAMO' => 'g',
        'TONELADA' => 'Ton',
        'METRO' => 'm',
        'CENTIMETRO' => 'cm',
        'MILIMETRO' => 'mm',
        'M2' => 'm2',
        'PULGADA' => 'in',
        'LITRO' => 'Lt',
        'MILILITRO' => 'ml',
        'GALON' => 'Gal',
        'CUNETE' => 'und',
        'CANECA' => 'und',
        'BOTELLA' => 'und',
        'GARRAFA' => 'und',
        'TUBO' => 'und',
        'JUEGO' => 'und',
        'KIT' => 'und',
        'PAR' => 'und',
        'ROLLO' => 'und',
        'CAJA' => 'und',
        'BOLSA' => 'und',
        'BULTO' => 'und',
        'FRASCO' => 'und',
        'BARRA' => 'und',
        'VARILLA' => 'und',
        'PIEZA' => 'und',
        'PLIEGO' => 'und',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable(),

                TextColumn::make('name')
                    ->label('Nombre / Referencia / Marca')
                    ->formatStateUsing(function ($state, $record) {
                        $parts = array_filter([
                            $state,
                            $record->reference,
                            $record->brand?->name ? "({$record->brand->name})" : null,
                        ]);

                        return implode(' · ', $parts);
                    })
                    ->searchable(query: function ($query, string $search) {
                        ArticleSearch::applyTo($query, $search);
                    }),

                TextColumn::make('type.name')
                    ->label('Tipo')
                    ->sortable(),

                TextColumn::make('stock_quantity')
                    ->label('Stock')
                    ->getStateUsing(fn ($record) => rtrim(rtrim(number_format($record->stock_quantity, 4, '.', ''), '0'), '.').' '.(self::$unitLabels[$record->unit_of_measure] ?? 'und'))
                    ->badge()
                    ->color(function ($record) {
                        $stock = (float) $record->stock_quantity;
                        if ($stock <= 0) {
                            return 'danger';
                        }
                        if ($stock <= (float) config('inventory.stock_minimo', 2)) {
                            return 'warning';
                        }

                        return 'success';
                    })
                    ->tooltip(function ($record) {
                        $stock = (float) $record->stock_quantity;
                        if ($stock <= 0) {
                            return 'Sin stock disponible';
                        }
                        if ($stock <= (float) config('inventory.stock_minimo', 2)) {
                            return 'Stock bajo';
                        }

                        return null;
                    })
                    ->sortable(),

                TextColumn::make('unit_of_measure')
                    ->label('Und.')
                    ->formatStateUsing(fn ($state) => self::$unitLabels[$state] ?? $state)
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('current_average_cost')
                    ->label('PPP')
                    ->money('COP')
                    ->sortable()
                    ->tooltip('Precio Promedio Ponderado — se actualiza automáticamente con cada entrada'),

                // Fix #9: ahora ordenable via orderByRaw
                TextColumn::make('valor_total_inventario')
                    ->label('Valor en Inventario')
                    ->getStateUsing(fn ($record) => $record->stock_quantity * $record->current_average_cost)
                    ->money('COP')
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderByRaw("(stock_quantity * current_average_cost) {$direction}");
                    })
                    ->color('success')
                    ->weight('bold')
                    ->tooltip('Stock Físico × PPP'),

                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('brand_id')
                    ->label('Marca')
                    ->options(fn () => Brand::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Todas las marcas'),

                SelectFilter::make('department_id')
                    ->label('Departamento')
                    ->options(fn () => Department::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Todos los departamentos'),

                SelectFilter::make('type_id')
                    ->label('Tipo')
                    ->options(fn () => Type::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('Todos los tipos'),

                TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->placeholder('Todos')
                    ->trueLabel('Solo activos')
                    ->falseLabel('Solo inactivos'),

                NumericFilter::make('stock_quantity', 'stock_quantity', 'Stock'),

                NumericFilter::make('current_average_cost', 'current_average_cost', 'Precio (PPP)'),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100]);
    }
}
