<?php

namespace App\Services;

use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Borra una entrada (o un producto de ella) junto con las salidas que quedan sin
 * cobertura de stock, y recalcula stock/costo promedio de los articulos afectados.
 *
 * "Sin cobertura" se decide repasando en orden cronologico los movimientos activos
 * del articulo desde el ultimo cierre, sin las lineas a borrar: la salida que en su
 * momento no alcanza stock se elimina. Nunca toca meses cerrados.
 */
class EntryDeletionService
{
    /**
     * @return array{blocked: ?string, requiresWholeEntry: bool, exits: Collection, simulation: array}
     */
    public function planEntryDeletion(InventoryTransaction $entry): array
    {
        return $this->plan($entry, $entry->details()->get());
    }

    /**
     * @return array{blocked: ?string, requiresWholeEntry: bool, exits: Collection, simulation: array}
     */
    public function planDetailDeletion(InventoryTransactionDetail $detail): array
    {
        $entry = $detail->transaction;

        if ($entry->details()->count() <= 1) {
            return [
                'blocked' => 'Es el único producto de la entrada: debe eliminar la entrada completa.',
                'requiresWholeEntry' => true,
                'exits' => collect(),
                'simulation' => [],
            ];
        }

        return $this->plan($entry, collect([$detail]));
    }

    /**
     * Texto para el aviso de confirmacion: que salidas se eliminaran junto con la entrada.
     */
    public function summarize(array $plan): string
    {
        if ($plan['exits']->isEmpty()) {
            return 'Esta acción no se puede deshacer.';
        }

        $lines = $plan['exits']->map(fn (array $e) => sprintf(
            '%s · %s · cant. %s · $ %s',
            Carbon::parse($e['date'])->format('d/m/Y'),
            $e['article'],
            rtrim(rtrim(number_format($e['quantity'], 4, '.', ''), '0'), '.'),
            number_format($e['value'], 2, '.', ',')
        ));

        return "También se eliminarán {$plan['exits']->count()} salida(s) que dependen de este stock:\n"
            .$lines->implode("\n")
            ."\nSe recalcularán stock, costos y totales. Esta acción no se puede deshacer.";
    }

    public function deleteEntry(InventoryTransaction $entry): void
    {
        $plan = $this->planEntryDeletion($entry);

        $this->execute($entry, $plan, fn () => $entry->delete());
    }

    public function deleteDetail(InventoryTransactionDetail $detail): void
    {
        $entry = $detail->transaction;
        $plan = $this->planDetailDeletion($detail);

        $this->execute($entry, $plan, fn () => $detail->delete());
    }

    private function execute(InventoryTransaction $entry, array $plan, callable $deleteEntryPart): void
    {
        if ($plan['blocked'] !== null) {
            throw new \RuntimeException($plan['blocked']);
        }

        $affectedDates = $plan['exits']->pluck('date')->push($entry->transaction_date->toDateString())->unique();

        DB::transaction(function () use ($plan, $deleteEntryPart) {
            foreach ($plan['exits'] as $exit) {
                $detail = InventoryTransactionDetail::find($exit['detail_id']);
                if (! $detail) {
                    continue;
                }

                $transaction = $detail->transaction;
                $detail->delete();

                if ($transaction && $transaction->details()->doesntExist()) {
                    $transaction->delete();
                }
            }

            $deleteEntryPart();

            foreach ($plan['simulation'] as $articleId => $state) {
                DB::table('articles')->where('id', $articleId)->update([
                    'stock_quantity' => round($state['stock'], 4),
                    'current_average_cost' => round($state['avg'], 4),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->forgetPreviewCache($affectedDates);

        Log::info('inventory.entry_deletion', [
            'user_id' => auth()->id(),
            'entry_id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'deleted_exit_details' => $plan['exits']->pluck('detail_id')->all(),
        ]);
    }

    private function plan(InventoryTransaction $entry, Collection $removed): array
    {
        $result = ['blocked' => null, 'requiresWholeEntry' => false, 'exits' => collect(), 'simulation' => []];

        if (strtolower($entry->type) !== 'entry') {
            $result['blocked'] = 'La transacción no es una entrada.';

            return $result;
        }

        if ($this->isMonthClosed($entry->transaction_date)) {
            $result['blocked'] = 'El mes de la entrada está cerrado: no se puede eliminar.';

            return $result;
        }

        // adjusts_detail_id es ON DELETE SET NULL: borrar la linea dejaria el ajuste huerfano.
        if (InventoryTransactionDetail::whereIn('adjusts_detail_id', $removed->pluck('id'))->exists()) {
            $result['blocked'] = 'Una línea de esta entrada tiene un ajuste enlazado: elimine o corrija el ajuste primero.';

            return $result;
        }

        // Un borrador no ha tocado stock ni costos: no hay nada que recalcular.
        if ($entry->status !== 'active') {
            return $result;
        }

        $removedIds = $removed->pluck('id')->all();
        $cutoff = $this->lastClosedCutoff();

        foreach ($removed->pluck('article_id')->unique() as $articleId) {
            $state = $this->startState((int) $articleId);

            $movements = DB::table('inventory_transaction_details as d')
                ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
                ->where('d.article_id', $articleId)
                ->where('t.status', 'active')
                ->when($cutoff, fn ($q) => $q->where('t.transaction_date', '>', $cutoff))
                ->whereNotIn('d.id', $removedIds)
                ->orderBy('d.created_at')
                ->orderBy('d.id')
                ->select('d.id', 'd.inventory_transaction_id', 'd.quantity', 'd.unit_cost', 'd.total_cost', 't.type', 't.transaction_date')
                ->get();

            $stock = $state['stock'];
            $avg = $state['avg'];

            foreach ($movements as $m) {
                $type = strtoupper($m->type);
                $qty = (float) $m->quantity;
                $cost = (float) $m->unit_cost;

                if ($type === 'ENTRY' || ($type === 'ADJUSTMENT' && $qty > 0)) {
                    $newStock = $stock + $qty;
                    $avg = $newStock > 0 ? (($stock * $avg) + ($qty * $cost)) / $newStock : $cost;
                    $stock = $newStock;

                    continue;
                }

                $need = abs($qty);

                if ($need > $stock + 1e-9) {
                    if ($type !== 'EXIT') {
                        $result['blocked'] = 'Un ajuste negativo dependería de esta entrada: corríjalo primero.';

                        return $result;
                    }

                    $article = DB::table('articles')->where('id', $articleId)->first();
                    $result['exits']->push([
                        'detail_id' => (int) $m->id,
                        'transaction_id' => (int) $m->inventory_transaction_id,
                        'date' => Carbon::parse($m->transaction_date)->toDateString(),
                        'article' => trim(($article->code ?? '').' '.($article->name ?? '')),
                        'quantity' => $need,
                        'value' => (float) $m->total_cost,
                    ]);

                    continue;
                }

                $stock -= $need;
                if ($stock <= 0) {
                    $avg = 0;
                }
            }

            $result['simulation'][(int) $articleId] = ['stock' => max(0, $stock), 'avg' => abs($avg)];
        }

        return $result;
    }

    private function isMonthClosed($date): bool
    {
        $date = Carbon::parse($date);

        return MonthlyClosure::query()
            ->where('month', $date->month)
            ->where('year', $date->year)
            ->where('is_closed', true)
            ->exists();
    }

    private function lastClosed(): ?MonthlyClosure
    {
        return MonthlyClosure::where('is_closed', true)->orderByDesc('year')->orderByDesc('month')->first();
    }

    private function lastClosedCutoff(): ?string
    {
        $last = $this->lastClosed();

        return $last ? Carbon::create($last->year, $last->month, 1)->endOfMonth()->toDateString() : null;
    }

    /**
     * @return array{stock: float, avg: float}
     */
    private function startState(int $articleId): array
    {
        $last = $this->lastClosed();

        if (! $last) {
            return ['stock' => 0.0, 'avg' => 0.0];
        }

        $snap = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $last->id)
            ->where('article_id', $articleId)
            ->first();

        return $snap
            ? ['stock' => (float) $snap->stock_quantity, 'avg' => (float) $snap->average_cost]
            : ['stock' => 0.0, 'avg' => 0.0];
    }

    private function forgetPreviewCache(Collection $dates): void
    {
        foreach ($dates as $date) {
            $date = Carbon::parse($date);
            $closure = MonthlyClosure::where('month', $date->month)->where('year', $date->year)->first();

            if ($closure) {
                Cache::forget(MonthlyMovementsExcelService::previewCacheKey($closure->id));
            }
        }
    }
}
