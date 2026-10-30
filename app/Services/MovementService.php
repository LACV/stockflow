<?php

namespace App\Services;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Models\User;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MovementService
{
    public function applyMovement(
        string $type,
        float $currentStock,
        float $currentAvgCost,
        float $quantity,
        float $unitCost
    ): array {
        $newStock = $currentStock;
        $newAvgCost = $currentAvgCost;
        $detailUpdates = [];

        $typeUpper = strtoupper($type);

        if ($typeUpper === 'ENTRY') {
            $newStock = $currentStock + $quantity;
            $newAvgCost = $newStock > 0
                ? (($currentStock * $currentAvgCost) + ($quantity * $unitCost)) / $newStock
                : $unitCost;
        } elseif ($typeUpper === 'EXIT') {
            $newStock = $currentStock - $quantity;
            $detailUpdates['unit_cost'] = $currentAvgCost;
            $detailUpdates['total_cost'] = $quantity * $currentAvgCost;
        } elseif ($typeUpper === 'ADJUSTMENT') {
            $newStock = $currentStock + $quantity;
            if ($quantity > 0) {
                $newAvgCost = $newStock > 0
                    ? (($currentStock * $currentAvgCost) + ($quantity * $unitCost)) / $newStock
                    : $unitCost;
            } else {
                $detailUpdates['unit_cost'] = $currentAvgCost;
                $detailUpdates['total_cost'] = abs($quantity) * $currentAvgCost;
            }
        }

        $newStock = max(0, $newStock);
        $newAvgCost = abs($newAvgCost);

        return [$newStock, $newAvgCost, $detailUpdates];
    }

    public function revertMovement(string $type, float $currentStock, float $quantity): float
    {
        $reverted = match (strtoupper($type)) {
            'ENTRY' => $currentStock - $quantity,
            'EXIT' => $currentStock + $quantity,
            'ADJUSTMENT' => $currentStock - $quantity,
            default => $currentStock,
        };

        return max(0, $reverted);
    }

    public function revertTransactionStock(InventoryTransaction $transaction): void
    {
        $details = $transaction->details()
            ->with('article')
            ->orderByDesc('id')
            ->get();

        foreach ($details as $detail) {
            $article = $detail->article;
            if (! $article) {
                continue;
            }

            $currentStock = (float) $article->stock_quantity;
            $currentAvgCost = (float) $article->current_average_cost;
            $quantity = (float) $detail->quantity;
            $unitCost = (float) $detail->unit_cost;
            $typeUpper = strtoupper($transaction->type);

            $newStock = $currentStock;
            $newAvgCost = $currentAvgCost;

            if ($typeUpper === 'ENTRY') {
                $newStock = $currentStock - $quantity;
                if ($newStock > 0) {
                    $totalValueAfter = ($currentStock * $currentAvgCost) - ($quantity * $unitCost);
                    $newAvgCost = $totalValueAfter / $newStock;
                } else {
                    $newAvgCost = 0;
                }
            } elseif ($typeUpper === 'EXIT') {
                $newStock = $currentStock + $quantity;
            } elseif ($typeUpper === 'ADJUSTMENT') {
                if ($quantity > 0) {
                    $newStock = $currentStock - $quantity;
                    if ($newStock > 0) {
                        $totalValueAfter = ($currentStock * $currentAvgCost) - ($quantity * $unitCost);
                        $newAvgCost = $totalValueAfter / $newStock;
                    } else {
                        $newAvgCost = 0;
                    }
                } else {
                    $newStock = $currentStock - $quantity;
                }
            }

            $newStock = max(0, $newStock);
            $newAvgCost = abs($newAvgCost);

            $article->stock_quantity = $newStock;
            $article->current_average_cost = $newAvgCost;
            $article->saveQuietly();

            ArticlePrice::where('article_id', $detail->article_id)
                ->where('transaction_id', $transaction->id)
                ->delete();
        }
    }

    public function activateTransaction(int $transactionId): void
    {
        $current = DB::table('inventory_transactions')
            ->where('id', $transactionId)
            ->value('status');

        if ($current === 'annulled') {
            return;
        }

        if ($current !== 'active') {
            DB::table('inventory_transactions')
                ->where('id', $transactionId)
                ->update(['status' => 'active']);
        }
    }

    /**
     * Pasa un borrador a activo aplicando su efecto en stock y costo.
     *
     * @return array{ok: bool, title: string, body: string}
     */
    public function finalizeTransaction(InventoryTransaction $transaction): array
    {
        if ($transaction->status !== 'inactive') {
            return ['ok' => false, 'title' => 'Ya guardado', 'body' => 'Este registro ya no es un borrador.'];
        }

        $date = Carbon::parse($transaction->transaction_date);
        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        $isReopened = $closure && $closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');
        $monthClosed = $closure && $closure->is_closed && ! $isReopened;

        if ($monthClosed) {
            $monthName = $date->locale('es')->isoFormat('MMMM');

            return ['ok' => false, 'title' => 'Mes cerrado', 'body' => "No se puede guardar porque {$monthName} {$date->year} ya está cerrado."];
        }

        $details = $transaction->details()->get();

        if ($details->isEmpty()) {
            return ['ok' => false, 'title' => 'Sin artículos', 'body' => 'Agregue al menos un artículo antes de guardar la factura.'];
        }

        $type = strtoupper($transaction->type);

        if (! $isReopened && in_array($type, ['EXIT', 'ADJUSTMENT'], true)) {
            $insuficientes = $this->validarStockSuficiente($details, $type);

            if (! empty($insuficientes)) {
                return [
                    'ok' => false,
                    'title' => 'Stock insuficiente',
                    'body' => "No se guardó la factura. Estos artículos no tienen stock suficiente:\n".implode("\n", $insuficientes),
                ];
            }
        }

        DB::transaction(function () use ($transaction, $details, $isReopened) {
            foreach ($details as $detail) {
                if ($isReopened) {
                    [, $newAvgCost] = $this->applyMovement(
                        $transaction->type,
                        0,
                        0,
                        (float) $detail->quantity,
                        (float) $detail->unit_cost,
                    );

                    if ($transaction->type === 'EXIT' || ($transaction->type === 'ADJUSTMENT' && (float) $detail->quantity < 0)) {
                        $detail->unit_cost = 0;
                    }

                    if (! $detail->total_cost || $detail->total_cost == 0) {
                        $detail->total_cost = abs((float) $detail->quantity) * (float) ($detail->unit_cost ?: 0);
                    }

                    $detail->resulting_average_cost = $newAvgCost;
                    $detail->saveQuietly();
                } else {
                    $article = $detail->article;

                    [$newStock, $newAvgCost, $detailUpdates] = $this->applyMovement(
                        $transaction->type,
                        (float) $article->stock_quantity,
                        (float) $article->current_average_cost,
                        (float) $detail->quantity,
                        (float) $detail->unit_cost,
                    );

                    foreach ($detailUpdates ?? [] as $key => $value) {
                        $detail->{$key} = $value;
                    }

                    if (! $detail->total_cost || $detail->total_cost == 0) {
                        $detail->total_cost = abs((float) $detail->quantity) * (float) $detail->unit_cost;
                    }

                    $detail->resulting_average_cost = $newAvgCost;
                    $detail->saveQuietly();

                    $article->stock_quantity = $newStock;
                    $article->current_average_cost = $newAvgCost;
                    $article->saveQuietly();

                    $this->createPriceRecord($article, $detail, $transaction->type, $transaction->transaction_date);
                    $this->notifyIfStockCritical($article, $newStock);
                }
            }

            $transaction->status = 'active';
            $transaction->saveQuietly();
        });

        return ['ok' => true, 'title' => 'Registro guardado', 'body' => 'El registro fue guardado exitosamente. Ya no es posible editarlo.'];
    }

    /**
     * Cantidad que otros despachos en borrador ya piden por articulo.
     *
     * Un borrador no resta stock hasta guardarse, asi que sin esto dos borradores
     * pueden prometer las mismas unidades. Solo cuentan borradores de salida de
     * meses todavia abiertos: los de un mes cerrado no deben bloquear stock.
     *
     * @return array<int, float> article_id => cantidad reservada
     */
    public function reservedByOtherDrafts(?int $excludeTransactionId = null): array
    {
        return DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.type', 'exit')
            ->where('t.status', 'inactive')
            ->when($excludeTransactionId, fn ($q) => $q->where('t.id', '!=', $excludeTransactionId))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('monthly_closures as c')
                    ->where('c.is_closed', true)
                    ->whereColumn('c.month', 't.month')
                    ->whereColumn('c.year', 't.year');
            })
            ->groupBy('d.article_id')
            ->selectRaw('d.article_id, sum(d.quantity) as qty')
            ->pluck('qty', 'article_id')
            ->map(fn ($qty) => (float) $qty)
            ->all();
    }

    /**
     * Agrega una linea de salida a un despacho validando el stock de forma atomica.
     *
     * Bloquea la fila del articulo mientras valida y crea la linea, para que dos
     * personas no agoten el mismo stock a la vez. En un despacho activo el observer
     * descuenta el stock al crear la linea; en un borrador solo se valida.
     * Disponible = stock vivo - lo que piden otros borradores - lo ya pedido en este
     * borrador (si lo es).
     *
     * @throws ValidationException
     */
    public function addExitLine(InventoryTransaction $transaction, array $data): InventoryTransactionDetail
    {
        $date = Carbon::parse($transaction->transaction_date);
        $closure = MonthlyClosure::where('month', $date->month)->where('year', $date->year)->first();
        $reopened = $closure && $closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');

        if ($closure && $closure->is_closed && ! $reopened) {
            throw ValidationException::withMessages([
                'quantity' => "El mes {$date->month}/{$date->year} ya está cerrado: no se pueden agregar retiros.",
            ]);
        }

        return DB::transaction(function () use ($transaction, $data) {
            $article = Article::whereKey($data['article_id'])->lockForUpdate()->firstOrFail();
            $qty = (float) $data['quantity'];

            $reserved = $this->reservedByOtherDrafts($transaction->id)[$article->id] ?? 0.0;
            $ownPending = $transaction->status === 'inactive'
                ? (float) $transaction->details()->where('article_id', $article->id)->sum('quantity')
                : 0.0;
            $available = (float) $article->stock_quantity - $reserved - $ownPending;

            if ($qty > $available + 1e-9) {
                $fmt = fn (float $n) => rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
                $detail = 'disponible '.$fmt(max(0, $available)).' und';

                if ($reserved > 0) {
                    $detail .= ', '.$fmt($reserved).' und ya las piden otros despachos en borrador';
                }

                throw ValidationException::withMessages([
                    'quantity' => "Stock insuficiente: {$detail}; se requieren ".$fmt($qty).' und.',
                ]);
            }

            $unit = (float) ($data['unit_cost'] ?? $article->current_average_cost);

            return $transaction->details()->create($data + [
                'unit_cost' => $unit,
                'total_cost' => $qty * $unit,
            ]);
        });
    }

    /**
     * Cantidad neta que salio de una linea de despacho tras sus ajustes.
     *
     * Los ajustes se expresan en terminos de stock (positivo = devuelve unidades,
     * negativo = quita mas), asi que la salida neta es la cantidad menos el ajuste.
     * Es solo de presentacion: no toca stock ni PPP.
     */
    public function netExitQuantity(float $quantity, float $adjustmentsStockSum): float
    {
        return $quantity - $adjustmentsStockSum;
    }

    public function syncTransactionStatus(?InventoryTransactionDetail $detail): void
    {
        if (! $detail) {
            return;
        }

        $transaction = $detail->transaction;
        if (! $transaction || $transaction->status === 'annulled') {
            return;
        }

        // Un borrador solo cambia de estado al finalizar (finalize()). Nunca debe
        // activarse al borrar un detalle, o la factura quedaría bloqueada sin guardar.
        if ($transaction->status === 'inactive') {
            return;
        }

        $hasDetails = $transaction->details()
            ->where('id', '!=', $detail->id)
            ->exists();

        $transaction->status = $hasDetails ? 'active' : 'inactive';
        $transaction->saveQuietly();
    }

    public function validarStockSuficiente($details, string $type): array
    {
        $type = strtoupper($type);

        if (! in_array($type, ['EXIT', 'ADJUSTMENT'], true)) {
            return [];
        }

        $necesidades = [];

        foreach ($details as $detail) {
            $articleId = (int) $detail->article_id;
            if (! isset($necesidades[$articleId])) {
                $necesidades[$articleId] = ['demanda' => 0.0, 'article' => $detail->article];
            }

            $qty = (float) $detail->quantity;
            if ($type === 'EXIT') {
                $necesidades[$articleId]['demanda'] += $qty;
            } elseif ($qty < 0) {
                $necesidades[$articleId]['demanda'] += abs($qty);
            }
        }

        $insuficientes = [];

        foreach ($necesidades as $articleId => $data) {
            $article = $data['article'] ?? Article::find($articleId);
            if (! $article) {
                continue;
            }

            $stock = (float) $article->stock_quantity;
            if ($data['demanda'] > $stock) {
                $insuficientes[] = "[{$article->code}] {$article->name}: disponible {$stock} und, se requieren {$data['demanda']} und.";
            }
        }

        return $insuficientes;
    }

    public function notifyIfStockCritical(Article $article, float $newStock): void
    {
        if ($newStock > 0) {
            return;
        }

        $stockLabel = $newStock < 0 ? 'Stock negativo' : 'Stock en cero';
        $color = $newStock < 0 ? 'danger' : 'warning';
        $icon = $newStock < 0 ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-exclamation-circle';

        $body = $newStock < 0
            ? "El artículo [{$article->code}] {$article->name} tiene stock negativo ({$newStock} und). Revisar movimientos."
            : "El artículo [{$article->code}] {$article->name} llegó a stock cero.";

        $notification = Notification::make()
            ->title($stockLabel)
            ->body($body)
            ->icon($icon)
            ->color($color)
            ->actions([
                Action::make('ver_articulo')
                    ->label('Ver artículo')
                    ->url(route('filament.admin.resources.articles.edit', ['record' => $article->id]))
                    ->markAsRead(),
            ]);

        $users = User::permission(['Update:Article', 'ViewAny:Article'])
            ->orWhereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->get();

        foreach ($users as $user) {
            $notification->sendToDatabase($user);
        }
    }

    public function createPriceRecord(
        Article $article,
        InventoryTransactionDetail $detail,
        string $transactionType,
        string $transactionDate,
    ): void {
        ArticlePrice::create([
            'article_id' => $article->id,
            'quantity' => abs((float) $detail->quantity),
            'price' => (float) $detail->unit_cost,
            'date' => $transactionDate,
            'source' => strtoupper($transactionType),
            'transaction_id' => $detail->transaction->id,
        ]);
    }

    public function updatePriceRecord(
        Article $article,
        InventoryTransactionDetail $detail,
        string $transactionType,
        string $transactionDate,
    ): void {
        $priceRecord = ArticlePrice::where('article_id', $article->id)
            ->where('transaction_id', $detail->transaction->id)
            ->first();

        if ($priceRecord) {
            $priceRecord->quantity = abs((float) $detail->quantity);
            $priceRecord->price = (float) $detail->unit_cost;
            $priceRecord->saveQuietly();
        } else {
            $this->createPriceRecord($article, $detail, $transactionType, $transactionDate);
        }
    }
}
