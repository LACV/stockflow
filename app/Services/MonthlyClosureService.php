<?php

namespace App\Services;

use App\Models\MonthlyClosure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MonthlyClosureService
{
    /**
     * Estado inicial de inventario (base legacy): article_id => ['stock' => float, 'avg' => float].
     * Se captura del primer cierre cerrado antes de regenerarlo y se complementa con
     * transacciones previas a ese mes para articulos que el cierre inicial no incluyo.
     */
    protected ?array $anchor = null;

    /**
     * Costos legacy por articulo (sin transacciones activas): article_id => float.
     */
    protected array $legacyCosts = [];

    public function regenerateBalances(MonthlyClosure $closure): void
    {
        $closure->closed_at = Carbon::now();
        $closure->saveQuietly();

        DB::table('monthly_closure_balances')
            ->where('monthly_closure_id', $closure->id)
            ->delete();

        $startDate = Carbon::create($closure->year, $closure->month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($closure->year, $closure->month, 1)->endOfMonth()->toDateString();

        $prevMonth = Carbon::create($closure->year, $closure->month, 1)->subMonth();
        $prevClosure = MonthlyClosure::where('month', $prevMonth->month)
            ->where('year', $prevMonth->year)
            ->where('is_closed', true)
            ->first();

        $movimientos = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->select('d.article_id', 't.type', 'd.quantity', 'd.unit_cost')
            ->orderBy('d.created_at', 'asc')
            ->get()
            ->groupBy('article_id');

        if ($movimientos->isEmpty()) {
            return;
        }

        $articleIds = $movimientos->keys()->toArray();

        $prevSnap = [];
        if ($prevClosure) {
            DB::table('monthly_closure_snapshots')
                ->where('monthly_closure_id', $prevClosure->id)
                ->whereIn('article_id', $articleIds)
                ->get()
                ->each(function ($s) use (&$prevSnap) {
                    $prevSnap[$s->article_id] = [
                        'stock' => (float) $s->stock_quantity,
                        'average_cost' => (float) $s->average_cost,
                    ];
                });
        } else {
            $this->ensureAnchor();
        }

        $articles = DB::table('articles')
            ->whereIn('id', $articleIds)
            ->select('id', 'stock_quantity', 'current_average_cost')
            ->get()
            ->keyBy('id');

        $now = Carbon::now()->toDateTimeString();
        $inserts = [];

        foreach ($articleIds as $articleId) {
            $article = $articles->get($articleId);
            if (! $article) {
                continue;
            }

            if ($prevClosure && isset($prevSnap[$articleId])) {
                $stock = $prevSnap[$articleId]['stock'];
                $avgCost = $prevSnap[$articleId]['average_cost'];
            } elseif (! $prevClosure && isset($this->anchor[$articleId])) {
                $stock = $this->anchor[$articleId]['stock'];
                $avgCost = $this->anchor[$articleId]['avg'];
            } else {
                $stock = 0;
                $avgCost = 0;
            }

            foreach ($movimientos[$articleId] as $mov) {
                $type = strtoupper($mov->type);
                $qty = (float) $mov->quantity;
                $cost = (float) $mov->unit_cost;

                if ($type === 'ENTRY') {
                    $newStock = $stock + $qty;
                    $avgCost = $newStock > 0 ? (($stock * $avgCost) + ($qty * $cost)) / $newStock : $cost;
                    $stock = $newStock;
                } elseif ($type === 'EXIT') {
                    $stock = max(0, $stock - abs($qty));
                    if ($stock <= 0) {
                        $avgCost = 0;
                    }
                } elseif ($type === 'ADJUSTMENT') {
                    if ($qty > 0) {
                        $newStock = $stock + $qty;
                        $avgCost = $newStock > 0 ? (($stock * $avgCost) + ($qty * $cost)) / $newStock : $cost;
                        $stock = $newStock;
                    } else {
                        $stock = max(0, $stock + $qty);
                        if ($stock <= 0) {
                            $avgCost = 0;
                        }
                    }
                }
            }

            if ($avgCost <= 0 && isset($this->legacyCosts[$articleId])) {
                $avgCost = (float) $this->legacyCosts[$articleId];
            }

            if ($stock > 0) {
                $inserts[] = [
                    'monthly_closure_id' => $closure->id,
                    'article_id' => $articleId,
                    'stock' => round($stock, 4),
                    'average_cost' => round($avgCost, 4),
                    'total_value' => round($stock * $avgCost, 4),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('monthly_closure_balances')->insert($chunk);
        }
    }

    /**
     * Re-cierre incremental: recalcula los artículos con movimientos en el mes y
     * preserva el snapshot existente de los artículos sin movimientos, que mantienen
     * sus valores exactos (sin residuo de punto flotante).
     */
    public function reclosePreservingExisting(MonthlyClosure $closure): void
    {
        $startDate = Carbon::create($closure->year, $closure->month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($closure->year, $closure->month, 1)->endOfMonth()->toDateString();

        $prevMonth = Carbon::create($closure->year, $closure->month, 1)->subMonth();
        $prevClosure = MonthlyClosure::where('month', $prevMonth->month)
            ->where('year', $prevMonth->year)
            ->where('is_closed', true)
            ->first();

        $existingSnap = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $closure->id)
            ->get()
            ->keyBy('article_id');

        $prevSnap = [];
        if ($prevClosure) {
            DB::table('monthly_closure_snapshots')
                ->where('monthly_closure_id', $prevClosure->id)
                ->get()
                ->each(function ($s) use (&$prevSnap) {
                    $prevSnap[$s->article_id] = [
                        'stock' => (float) $s->stock_quantity,
                        'average_cost' => (float) $s->average_cost,
                    ];
                });
        } else {
            $this->ensureAnchor();
        }

        $movimientos = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->whereBetween('t.transaction_date', [$startDate, $endDate])
            ->select('d.article_id', 't.type', 'd.quantity', 'd.unit_cost')
            ->orderBy('d.created_at', 'asc')
            ->get()
            ->groupBy('article_id');

        // Todo articulo con movimientos en el mes se recalcula desde el cierre anterior:
        // sus transacciones pudieron cambiar durante la reapertura (ej. salidas nuevas),
        // y conservar el snapshot viejo dejaria el valor final desactualizado.
        $newArticleIds = $movimientos->keys()->values()->all();

        $articles = DB::table('articles')
            ->whereIn('id', $newArticleIds)
            ->select('id', 'stock_quantity', 'current_average_cost')
            ->get()
            ->keyBy('id');

        $now = Carbon::now()->toDateTimeString();
        $newEntries = [];

        foreach ($newArticleIds as $articleId) {
            if (! isset($articles[$articleId])) {
                continue;
            }

            if ($prevClosure && isset($prevSnap[$articleId])) {
                $stock = $prevSnap[$articleId]['stock'];
                $avgCost = $prevSnap[$articleId]['average_cost'];
            } elseif (! $prevClosure && isset($this->anchor[$articleId])) {
                $stock = $this->anchor[$articleId]['stock'];
                $avgCost = $this->anchor[$articleId]['avg'];
            } else {
                $stock = 0;
                $avgCost = 0;
            }

            foreach ($movimientos[$articleId] as $mov) {
                $type = strtoupper($mov->type);
                $qty = (float) $mov->quantity;
                $cost = (float) $mov->unit_cost;

                if ($type === 'ENTRY') {
                    $newStock = $stock + $qty;
                    $avgCost = $newStock > 0 ? (($stock * $avgCost) + ($qty * $cost)) / $newStock : $cost;
                    $stock = $newStock;
                } elseif ($type === 'EXIT') {
                    $stock = max(0, $stock - abs($qty));
                    if ($stock <= 0) {
                        $avgCost = 0;
                    }
                } elseif ($type === 'ADJUSTMENT') {
                    if ($qty > 0) {
                        $newStock = $stock + $qty;
                        $avgCost = $newStock > 0 ? (($stock * $avgCost) + ($qty * $cost)) / $newStock : $cost;
                        $stock = $newStock;
                    } else {
                        $stock = max(0, $stock + $qty);
                        if ($stock <= 0) {
                            $avgCost = 0;
                        }
                    }
                }
            }

            if ($avgCost <= 0 && isset($this->legacyCosts[$articleId])) {
                $avgCost = (float) $this->legacyCosts[$articleId];
            }

            if ($stock > 0) {
                $newEntries[] = [
                    'monthly_closure_id' => $closure->id,
                    'article_id' => $articleId,
                    'stock_quantity' => round($stock, 4),
                    'average_cost' => round($avgCost, 4),
                    'total_value' => round($stock * $avgCost, 4),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $closure->id)
            ->delete();

        $inserts = [];

        foreach ($existingSnap as $articleId => $snap) {
            if ($movimientos->has($articleId)) {
                continue;
            }

            if ((float) $snap->stock_quantity > 0) {
                $inserts[] = [
                    'monthly_closure_id' => $closure->id,
                    'article_id' => $articleId,
                    'stock_quantity' => $snap->stock_quantity,
                    'average_cost' => $snap->average_cost,
                    'total_value' => $snap->total_value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach ($newEntries as $entry) {
            $inserts[] = $entry;
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('monthly_closure_snapshots')->insert($chunk);
        }

        DB::table('monthly_closure_balances')
            ->where('monthly_closure_id', $closure->id)
            ->delete();

        $balanceInserts = array_map(fn ($row) => [
            'monthly_closure_id' => $row['monthly_closure_id'],
            'article_id' => $row['article_id'],
            'stock' => $row['stock_quantity'],
            'average_cost' => $row['average_cost'],
            'total_value' => $row['total_value'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ], $inserts);

        foreach (array_chunk($balanceInserts, 500) as $chunk) {
            DB::table('monthly_closure_balances')->insert($chunk);
        }
    }

    public function regenerateBalancesFromSnapshots(MonthlyClosure $closure): void
    {
        DB::table('monthly_closure_balances')
            ->where('monthly_closure_id', $closure->id)
            ->delete();

        $snapshots = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $closure->id)
            ->get();

        if ($snapshots->isEmpty()) {
            return;
        }

        $now = Carbon::now()->toDateTimeString();
        $inserts = [];

        foreach ($snapshots as $snap) {
            $stock = (float) $snap->stock_quantity;
            if ($stock <= 0) {
                continue;
            }

            $inserts[] = [
                'monthly_closure_id' => $closure->id,
                'article_id' => $snap->article_id,
                'stock' => round($stock, 4),
                'average_cost' => round((float) $snap->average_cost, 4),
                'total_value' => round($stock * (float) $snap->average_cost, 4),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('monthly_closure_balances')->insert($chunk);
        }
    }

    public function regenerateSnapshot(MonthlyClosure $closure): void
    {
        if ($closure->isReopened()) {
            return;
        }

        $prevDate = Carbon::create($closure->year, $closure->month, 1)->subMonth();
        $prevClosure = MonthlyClosure::where('month', $prevDate->month)
            ->where('year', $prevDate->year)
            ->where('is_closed', true)
            ->first();

        if (! $prevClosure) {
            $this->ensureAnchor();
        }

        DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $closure->id)
            ->delete();

        $now = Carbon::now()->toDateTimeString();
        $inserts = [];

        $articles = DB::table('articles')
            ->where('is_active', true)
            ->select('id', 'stock_quantity', 'current_average_cost')
            ->get()
            ->keyBy('id');

        $balances = DB::table('monthly_closure_balances')
            ->where('monthly_closure_id', $closure->id)
            ->get()
            ->keyBy('article_id');

        $prevSnap = collect();
        if ($prevClosure) {
            $prevSnap = DB::table('monthly_closure_snapshots')
                ->where('monthly_closure_id', $prevClosure->id)
                ->get()
                ->keyBy('article_id');
        }

        if ($prevClosure) {
            $ids = collect(array_merge($balances->keys()->all(), $prevSnap->keys()->all()))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        } else {
            $ids = collect(array_merge($balances->keys()->all(), array_keys($this->anchor)))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $movedIds = [];
        if ($prevClosure) {
            $startDate = Carbon::create($closure->year, $closure->month, 1)->startOfMonth()->toDateString();
            $endDate = Carbon::create($closure->year, $closure->month, 1)->endOfMonth()->toDateString();
            $movedIds = DB::table('inventory_transaction_details as d')
                ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
                ->where('t.status', 'active')
                ->whereBetween('t.transaction_date', [$startDate, $endDate])
                ->distinct()
                ->pluck('d.article_id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        foreach ($ids as $articleId) {
            $article = $articles->get($articleId);
            if (! $article) {
                continue;
            }

            if ($balances->has($articleId)) {
                $balance = $balances->get($articleId);
                $stock = (float) $balance->stock;
                $avgCost = (float) $balance->average_cost;
            } elseif ($prevClosure && $prevSnap->has($articleId)) {
                if (in_array($articleId, $movedIds, true)) {
                    continue;
                }
                $prev = $prevSnap->get($articleId);
                $stock = (float) $prev->stock_quantity;
                $avgCost = (float) $prev->average_cost;
            } elseif (! $prevClosure && isset($this->anchor[$articleId])) {
                $stock = $this->anchor[$articleId]['stock'];
                $avgCost = $this->anchor[$articleId]['avg'];
            } else {
                continue;
            }

            if ($stock <= 0) {
                continue;
            }

            if ($avgCost <= 0 && isset($this->legacyCosts[$articleId])) {
                $avgCost = (float) $this->legacyCosts[$articleId];
            }

            $totValue = round($stock * $avgCost, 4);

            $inserts[] = [
                'monthly_closure_id' => $closure->id,
                'article_id' => $articleId,
                'stock_quantity' => round($stock, 4),
                'average_cost' => round($avgCost, 4),
                'total_value' => $totValue,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('monthly_closure_snapshots')->insert($chunk);
        }
    }

    public function reconcileLiveInventory(MonthlyClosure $lastClosed): int
    {
        $cutoff = Carbon::create($lastClosed->year, $lastClosed->month, 1)
            ->endOfMonth()
            ->toDateString();

        $snap = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $lastClosed->id)
            ->select('article_id', 'stock_quantity', 'average_cost')
            ->get()
            ->keyBy('article_id');

        $post = DB::table('inventory_transaction_details as d')
            ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
            ->where('t.status', 'active')
            ->where('t.transaction_date', '>', $cutoff)
            ->orderBy('t.transaction_date', 'asc')
            ->orderBy('d.created_at', 'asc')
            ->select('d.article_id', 't.type', 'd.quantity', 'd.unit_cost')
            ->get()
            ->groupBy('article_id');

        $now = Carbon::now()->toDateTimeString();
        $updated = 0;

        foreach ($snap as $articleId => $row) {
            $stock = (float) $row->stock_quantity;
            $avg = (float) $row->average_cost;

            foreach ($post->get($articleId, collect()) as $mov) {
                $type = strtoupper($mov->type);
                $qty = (float) $mov->quantity;
                $cost = (float) $mov->unit_cost;

                if ($type === 'ENTRY' || ($type === 'ADJUSTMENT' && $qty > 0)) {
                    $newStock = $stock + $qty;
                    $avg = $newStock > 0
                        ? (($stock * $avg) + ($qty * $cost)) / $newStock
                        : $cost;
                    $stock = $newStock;
                } else {
                    $stock = max(0, $stock - abs($qty));
                    if ($stock <= 0) {
                        $avg = 0;
                    }
                }
            }

            $stock = max(0, $stock);
            $avg = abs($avg);

            DB::table('articles')
                ->where('id', $articleId)
                ->update([
                    'stock_quantity' => round($stock, 4),
                    'current_average_cost' => round($avg, 4),
                    'updated_at' => $now,
                ]);

            $updated++;
        }

        $notInSnap = DB::table('articles')
            ->where('is_active', true)
            ->whereNotIn('id', $snap->keys()->all())
            ->pluck('id');

        foreach ($notInSnap as $articleId) {
            $stock = 0.0;
            $avg = 0.0;

            foreach ($post->get($articleId, collect()) as $mov) {
                $type = strtoupper($mov->type);
                $qty = (float) $mov->quantity;
                $cost = (float) $mov->unit_cost;

                if ($type === 'ENTRY' || ($type === 'ADJUSTMENT' && $qty > 0)) {
                    $newStock = $stock + $qty;
                    $avg = $newStock > 0
                        ? (($stock * $avg) + ($qty * $cost)) / $newStock
                        : $cost;
                    $stock = $newStock;
                } else {
                    $stock = max(0, $stock - abs($qty));
                    if ($stock <= 0) {
                        $avg = 0;
                    }
                }
            }

            DB::table('articles')
                ->where('id', $articleId)
                ->update([
                    'stock_quantity' => round($stock, 4),
                    'current_average_cost' => round($avg, 4),
                    'updated_at' => $now,
                ]);

            $updated++;
        }

        return $updated;
    }

    public function zeroStalePppOnDepleted(): int
    {
        return DB::table('articles')
            ->where('is_active', true)
            ->where('stock_quantity', '<=', 0)
            ->where('current_average_cost', '>', 0)
            ->whereIn('id', function ($query) {
                $query->select('article_id')->from('inventory_transaction_details')->distinct();
            })
            ->update([
                'current_average_cost' => 0,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
    }

    public function finalizePendingTransactions(MonthlyClosure $closure): void
    {
        $startDate = Carbon::create($closure->year, $closure->month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($closure->year, $closure->month, 1)->endOfMonth()->toDateString();

        $pending = DB::table('inventory_transactions')
            ->where('status', 'inactive')
            ->whereBetween('transaction_date', [$startDate, $endDate])
            ->get();

        foreach ($pending as $tx) {
            $details = DB::table('inventory_transaction_details')
                ->where('inventory_transaction_id', $tx->id)
                ->get();

            foreach ($details as $detail) {
                $qty = (float) $detail->quantity;
                $cost = (float) $detail->unit_cost;

                $typeUpper = strtoupper($tx->type);
                $newAvgCost = $cost;

                if ($typeUpper === 'ENTRY') {
                    $newAvgCost = $cost;
                } elseif ($typeUpper === 'EXIT') {
                    $cost = 0;
                } elseif ($typeUpper === 'ADJUSTMENT') {
                    if ($qty <= 0) {
                        $cost = 0;
                    }
                }

                $totalCost = abs($qty) * ($cost ?: (float) $detail->unit_cost);

                DB::table('inventory_transaction_details')
                    ->where('id', $detail->id)
                    ->update([
                        'total_cost' => $totalCost,
                        'resulting_average_cost' => $newAvgCost,
                    ]);
            }

            DB::table('inventory_transactions')
                ->where('id', $tx->id)
                ->update(['status' => 'active']);
        }
    }

    /**
     * Captura la base inicial de inventario (ancla legacy) y los costos legacy.
     * Debe ejecutarse ANTES de regenerar el primer cierre, mientras sus snapshots
     * siguen siendo los originales. El ancla es el snapshot del primer cierre cerrado
     * complementado con las transacciones previas a ese mes para articulos que el
     * cierre inicial omitio.
     */
    private function ensureAnchor(): void
    {
        if ($this->anchor !== null) {
            return;
        }

        $first = MonthlyClosure::where('is_closed', true)
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->first();

        $anchor = [];

        if ($first) {
            DB::table('monthly_closure_snapshots')
                ->where('monthly_closure_id', $first->id)
                ->get()
                ->each(function ($s) use (&$anchor) {
                    $stock = (float) $s->stock_quantity;
                    if ($stock > 0) {
                        $anchor[$s->article_id] = [
                            'stock' => $stock,
                            'avg' => (float) $s->average_cost,
                        ];
                    }
                });

            if (empty($anchor)) {
                DB::table('articles')
                    ->where('is_active', true)
                    ->where('stock_quantity', '>', 0)
                    ->select('id', 'stock_quantity', 'current_average_cost')
                    ->get()
                    ->each(function ($a) use (&$anchor) {
                        $anchor[$a->id] = [
                            'stock' => (float) $a->stock_quantity,
                            'avg' => (float) $a->current_average_cost,
                        ];
                    });
            }

            $firstMonth = Carbon::create($first->year, $first->month, 1)
                ->startOfMonth()
                ->toDateString();

            $pre = DB::table('inventory_transaction_details as d')
                ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
                ->where('t.status', 'active')
                ->where('t.transaction_date', '<', $firstMonth)
                ->orderBy('t.transaction_date', 'asc')
                ->orderBy('d.created_at', 'asc')
                ->select('d.article_id', 't.type', 'd.quantity', 'd.unit_cost')
                ->get()
                ->groupBy('article_id');

            foreach ($pre as $articleId => $movs) {
                if (isset($anchor[$articleId])) {
                    continue;
                }

                $stock = 0.0;
                $avg = 0.0;

                foreach ($movs as $mov) {
                    $type = strtoupper($mov->type);
                    $qty = (float) $mov->quantity;
                    $cost = (float) $mov->unit_cost;

                    if ($type === 'ENTRY' || ($type === 'ADJUSTMENT' && $qty > 0)) {
                        $new = $stock + $qty;
                        $avg = $new > 0 ? (($stock * $avg) + ($qty * $cost)) / $new : $cost;
                        $stock = $new;
                    } else {
                        $stock -= abs($qty);
                        if ($stock <= 0) {
                            $stock = 0;
                            $avg = 0;
                        }
                    }
                }

                if ($stock > 0) {
                    $anchor[$articleId] = ['stock' => $stock, 'avg' => $avg];
                }
            }

            $this->legacyCosts = DB::table('monthly_closure_snapshots')
                ->where('monthly_closure_id', $first->id)
                ->where('stock_quantity', '>', 0)
                ->where('average_cost', '>', 0)
                ->whereNotIn('article_id', function ($q) {
                    $q->select('d.article_id')
                        ->from('inventory_transaction_details as d')
                        ->join('inventory_transactions as t', 't.id', '=', 'd.inventory_transaction_id')
                        ->where('t.status', 'active');
                })
                ->get()
                ->keyBy('article_id')
                ->map(fn ($s) => (float) $s->average_cost)
                ->all();
        }

        $this->anchor = $anchor;
    }
}
