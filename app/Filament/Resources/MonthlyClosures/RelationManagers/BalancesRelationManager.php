<?php

namespace App\Filament\Resources\MonthlyClosures\RelationManagers;

use App\Models\Article;
use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class BalancesRelationManager extends RelationManager
{
    protected static string $relationship = 'balances';

    protected static ?string $title = 'Artículos del Mes';

    protected static ?string $modelLabel = 'Balance de Artículo';

    protected static ?string $pluralModelLabel = 'Balances de Artículos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        $closure = $this->ownerRecord;

        if ($closure->is_closed) {
            return $this->closedTable($table);
        }

        return $this->previewTable($table, $closure);
    }

    // ------------------------------------------------------------------
    // Tabla cuando el mes YA está cerrado → leer balances guardados
    // ------------------------------------------------------------------
    protected function closedTable(Table $table): Table
    {
        return $table
            ->columns($this->closedColumns())
            ->recordActions([])
            ->headerActions([])
            ->defaultSort('article.name')
            ->striped()
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('Sin artículos')
            ->emptyStateDescription('Este cierre no tiene artículos asociados.');
    }

    // ------------------------------------------------------------------
    // Tabla cuando el mes AÚN NO está cerrado → vista previa en vivo
    // Muestra TODOS los artículos activos con su stock proyectado
    // ------------------------------------------------------------------
    protected function previewTable(Table $table, $closure): Table
    {
        $startDate = Carbon::create($closure->year, $closure->month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($closure->year, $closure->month, 1)->endOfMonth()->toDateString();

        // Movimientos del mes
        $movimientos = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->select('d.article_id', 't.type', 'd.quantity', 'd.unit_cost', 'd.created_at')
            ->orderBy('d.created_at', 'asc')
            ->get()
            ->groupBy('article_id');

        // Cierre anterior para stock inicial
        $prevMonth = Carbon::create($closure->year, $closure->month, 1)->subMonth();
        $prevClosure = MonthlyClosure::where('month', $prevMonth->month)
            ->where('year', $prevMonth->year)
            ->where('is_closed', true)
            ->first();

        $stockAnterior = [];
        if ($prevClosure) {
            DB::table('monthly_closure_balances')
                ->where('monthly_closure_id', $prevClosure->id)
                ->get()
                ->each(fn ($b) => $stockAnterior[$b->article_id] = [
                    'stock' => (float) $b->stock,
                    'average_cost' => (float) $b->average_cost,
                ]);
        }

        // Todos los artículos activos
        $todosLosArticulos = DB::table('articles')
            ->where('is_active', true)
            ->select('id', 'stock_quantity', 'current_average_cost')
            ->get()
            ->keyBy('id');

        // Calcular preview para cada artículo
        $preview = [];

        foreach ($todosLosArticulos as $articleId => $article) {

            if ($prevClosure && isset($stockAnterior[$articleId])) {
                $stock = $stockAnterior[$articleId]['stock'];
                $avgCost = $stockAnterior[$articleId]['average_cost'];
            } elseif ($movimientos->has($articleId)) {
                $stockMes = 0;
                foreach ($movimientos[$articleId] as $mov) {
                    $type = strtoupper($mov->type);
                    $qty = (float) $mov->quantity;
                    if ($type === 'ENTRY') {
                        $stockMes += $qty;
                    } elseif ($type === 'EXIT') {
                        $stockMes -= $qty;
                    } elseif ($type === 'ADJUSTMENT') {
                        $stockMes += $qty;
                    }
                }
                $stock = max(0, (float) $article->stock_quantity - $stockMes);

                // Replay pre-month transactions for correct starting avg cost
                $preAvgCost = 0;
                $preStock = 0;
                $preDetails = DB::table('inventory_transaction_details as d')
                    ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
                    ->where('d.article_id', $articleId)
                    ->where('t.status', 'active')
                    ->where('t.transaction_date', '<', $startDate)
                    ->orderBy('t.transaction_date', 'asc')
                    ->orderBy('d.created_at', 'asc')
                    ->select('t.type', 'd.quantity', 'd.unit_cost')
                    ->get();

                foreach ($preDetails as $pre) {
                    $preType = strtoupper($pre->type);
                    $preQty = (float) $pre->quantity;
                    $preCost = (float) $pre->unit_cost;

                    if ($preType === 'ENTRY' || ($preType === 'ADJUSTMENT' && $preQty > 0)) {
                        $newPre = $preStock + $preQty;
                        $preAvgCost = $newPre > 0
                            ? (($preStock * $preAvgCost) + ($preQty * $preCost)) / $newPre
                            : $preCost;
                        $preStock = $newPre;
                    } elseif ($preType === 'EXIT') {
                        $preStock -= $preQty;
                    } else {
                        $preStock += $preQty;
                    }
                }
                $avgCost = $preAvgCost;
            } else {
                $stock = (float) $article->stock_quantity;
                $avgCost = (float) $article->current_average_cost;
            }

            if ($movimientos->has($articleId)) {
                foreach ($movimientos[$articleId] as $mov) {
                    $type = strtoupper($mov->type);
                    $qty = (float) $mov->quantity;
                    $cost = (float) $mov->unit_cost;

                    if ($type === 'ENTRY') {
                        $newStock = $stock + $qty;
                        $avgCost = $newStock > 0
                            ? (($stock * $avgCost) + ($qty * $cost)) / $newStock
                            : $cost;
                        $stock = $newStock;
                    } elseif ($type === 'EXIT') {
                        $stock -= $qty;
                    } elseif ($type === 'ADJUSTMENT') {
                        if ($qty > 0) {
                            $newStock = $stock + $qty;
                            $avgCost = $newStock > 0
                                ? (($stock * $avgCost) + ($qty * $cost)) / $newStock
                                : $cost;
                            $stock = $newStock;
                        } else {
                            $stock += $qty;
                        }
                    }
                }
            }

            $preview[$articleId] = [
                'stock' => round($stock, 4),
                'average_cost' => round($avgCost, 4),
                'total_value' => round($stock * $avgCost, 4),
            ];
        }

        $articleIdsWithStock = collect($preview)
            ->filter(fn ($p) => $p['stock'] > 0)
            ->keys()
            ->toArray();

        return $table
            ->query(
                Article::query()
                    ->where('is_active', true)
                    ->whereIn('id', $articleIdsWithStock)
                    ->with(['brand', 'department'])
            )
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('name')
                    ->label('Artículo')
                    ->searchable()
                    ->sortable()
                    ->icon('heroicon-o-cube')
                    ->iconColor('warning')
                    ->weight('medium'),

                TextColumn::make('reference')
                    ->label('Referencia')
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('brand.name')
                    ->label('Marca')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('department.name')
                    ->label('Departamento')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('stock_preview')
                    ->label('Stock al Cierre')
                    ->getStateUsing(fn ($record) => $preview[$record->id]['stock'] ?? 0)
                    ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.').' und')
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($record) => ($preview[$record->id]['stock'] ?? 0) > 0
                        ? 'success'
                        : (($preview[$record->id]['stock'] ?? 0) < 0 ? 'danger' : 'warning')),

                TextColumn::make('average_cost_preview')
                    ->label('Costo Promedio')
                    ->getStateUsing(fn ($record) => $preview[$record->id]['average_cost'] ?? 0)
                    ->money('COP', true)
                    ->alignEnd(),

                TextColumn::make('total_value_preview')
                    ->label('Valor Total')
                    ->getStateUsing(fn ($record) => $preview[$record->id]['total_value'] ?? 0)
                    ->money('COP', true)
                    ->alignEnd()
                    ->weight('bold')
                    ->color('warning'),
            ])
            ->recordActions([])
            ->headerActions([])
            ->defaultSort('name')
            ->striped()
            ->emptyStateIcon('heroicon-o-cube')
            ->emptyStateHeading('Sin artículos en inventario')
            ->emptyStateDescription('No hay artículos activos registrados.');
    }

    // ------------------------------------------------------------------
    // Columnas para el mes cerrado
    // ------------------------------------------------------------------
    protected function closedColumns(): array
    {
        return [
            TextColumn::make('article.code')
                ->label('Código')
                ->searchable()
                ->sortable()
                ->badge()
                ->color('gray'),

            TextColumn::make('article.name')
                ->label('Artículo')
                ->searchable()
                ->sortable()
                ->icon('heroicon-o-cube')
                ->iconColor('primary')
                ->weight('medium'),

            TextColumn::make('article.reference')
                ->label('Referencia')
                ->searchable()
                ->placeholder('—')
                ->toggleable(),

            TextColumn::make('article.brand.name')
                ->label('Marca')
                ->sortable()
                ->toggleable(),

            TextColumn::make('article.department.name')
                ->label('Departamento')
                ->sortable()
                ->toggleable(),

            TextColumn::make('stock')
                ->label('Stock al Cierre')
                ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format($state, 4, '.', ''), '0'), '.').' und')
                ->sortable()
                ->alignCenter()
                ->badge()
                ->color(fn ($state) => $state > 0 ? 'success' : ($state < 0 ? 'danger' : 'warning')),

            TextColumn::make('average_cost')
                ->label('Costo Promedio')
                ->money('COP', true)
                ->sortable()
                ->alignEnd(),

            TextColumn::make('total_value')
                ->label('Valor Total')
                ->money('COP', true)
                ->sortable()
                ->alignEnd()
                ->weight('bold')
                ->color('success'),
        ];
    }
}
