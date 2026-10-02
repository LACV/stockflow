<?php

namespace App\Observers;

use App\Models\ArticlePrice;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Services\MovementService;
use Carbon\Carbon;

class InventoryTransactionDetailObserver
{
    public function __construct(
        private MovementService $movementService,
    ) {}

    public function created(InventoryTransactionDetail $detail): void
    {
        $detail->load('transaction', 'article');
        $article = $detail->article;
        $transaction = $detail->transaction;

        if (! $article || ! $transaction) {
            return;
        }

        if ($transaction->status === 'inactive') {
            if (! $detail->total_cost || $detail->total_cost == 0) {
                $detail->total_cost = abs((float) $detail->quantity) * (float) $detail->unit_cost;
                $detail->saveQuietly();
            }

            return;
        }

        if ($this->isInReopenedMonth($transaction)) {
            [, $newAvgCost] = $this->movementService->applyMovement(
                $transaction->type,
                0,
                0,
                (float) $detail->quantity,
                (float) $detail->unit_cost,
            );

            if (! $detail->total_cost || $detail->total_cost == 0) {
                $detail->total_cost = abs($detail->quantity) * $detail->unit_cost;
            }

            $detail->resulting_average_cost = $newAvgCost;
            $detail->saveQuietly();

            return;
        }

        $currentStock = (float) $article->stock_quantity;
        $currentAvgCost = (float) $article->current_average_cost;

        [$newStock, $newAvgCost, $detailUpdates] = $this->movementService->applyMovement(
            $transaction->type,
            $currentStock,
            $currentAvgCost,
            (float) $detail->quantity,
            (float) $detail->unit_cost,
        );

        if (! empty($detailUpdates)) {
            foreach ($detailUpdates as $key => $value) {
                $detail->{$key} = $value;
            }
        }

        if (! $detail->total_cost || $detail->total_cost == 0) {
            $detail->total_cost = abs($detail->quantity) * $detail->unit_cost;
        }

        $detail->resulting_average_cost = $newAvgCost;
        $detail->saveQuietly();

        $article->stock_quantity = $newStock;
        $article->current_average_cost = $newAvgCost;
        $article->saveQuietly();

        $this->movementService->createPriceRecord(
            $article,
            $detail,
            $transaction->type,
            $transaction->transaction_date ?? now()->toDateString(),
        );

        $this->movementService->activateTransaction($transaction->id);

        $this->movementService->notifyIfStockCritical($article, $newStock);
    }

    public function updated(InventoryTransactionDetail $detail): void
    {
        $detail->load('transaction', 'article');
        $article = $detail->article;
        $transaction = $detail->transaction;

        if (! $article || ! $transaction) {
            return;
        }

        if ($transaction->status === 'inactive') {
            if (! $detail->total_cost || $detail->total_cost == 0) {
                $detail->total_cost = abs((float) $detail->quantity) * (float) $detail->unit_cost;
            }
            $detail->resulting_average_cost = 0;
            $detail->saveQuietly();

            return;
        }

        if ($this->isInReopenedMonth($transaction)) {
            [, $newAvgCost] = $this->movementService->applyMovement(
                $transaction->type,
                0,
                0,
                (float) $detail->quantity,
                (float) $detail->unit_cost,
            );

            $detail->total_cost = abs($detail->quantity) * $detail->unit_cost;
            $detail->resulting_average_cost = $newAvgCost;
            $detail->saveQuietly();

            return;
        }

        $typeUpper = strtoupper($transaction->type);

        $currentStock = (float) $article->stock_quantity;
        $currentAvgCost = (float) $article->current_average_cost;
        $oldQuantity = (float) $detail->getOriginal('quantity');
        $oldUnitCost = (float) $detail->getOriginal('unit_cost');

        $stockAfterRevert = max(0, $currentStock);
        $avgCostAfterRevert = $currentAvgCost;

        if ($typeUpper === 'ENTRY') {
            $stockAfterRevert = $currentStock - $oldQuantity;
            if ($stockAfterRevert > 0) {
                $totalValueAfter = ($currentStock * $currentAvgCost) - ($oldQuantity * $oldUnitCost);
                $avgCostAfterRevert = $totalValueAfter / $stockAfterRevert;
            } else {
                $avgCostAfterRevert = 0;
            }
        } elseif ($typeUpper === 'EXIT') {
            $stockAfterRevert = $currentStock + $oldQuantity;
        } elseif ($typeUpper === 'ADJUSTMENT') {
            if ($oldQuantity > 0) {
                $stockAfterRevert = $currentStock - $oldQuantity;
                if ($stockAfterRevert > 0) {
                    $totalValueAfter = ($currentStock * $currentAvgCost) - ($oldQuantity * $oldUnitCost);
                    $avgCostAfterRevert = $totalValueAfter / $stockAfterRevert;
                } else {
                    $avgCostAfterRevert = 0;
                }
            } else {
                $stockAfterRevert = $currentStock - $oldQuantity;
            }
        }

        $stockAfterRevert = max(0, $stockAfterRevert);
        $avgCostAfterRevert = abs($avgCostAfterRevert);

        [$newStock, $newAvgCost, $detailUpdates] = $this->movementService->applyMovement(
            $transaction->type,
            $stockAfterRevert,
            $avgCostAfterRevert,
            (float) $detail->quantity,
            (float) $detail->unit_cost,
        );

        if (! empty($detailUpdates)) {
            foreach ($detailUpdates as $key => $value) {
                $detail->{$key} = $value;
            }
        }
        $detail->total_cost = abs($detail->quantity) * $detail->unit_cost;
        $detail->resulting_average_cost = $newAvgCost;
        $detail->saveQuietly();

        $article->stock_quantity = $newStock;
        $article->current_average_cost = $newAvgCost;
        $article->saveQuietly();

        $this->movementService->updatePriceRecord(
            $article,
            $detail,
            $transaction->type,
            $transaction->transaction_date ?? now()->toDateString(),
        );

        $this->movementService->notifyIfStockCritical($article, $newStock);
    }

    public function deleting(InventoryTransactionDetail $detail): void
    {
        $detail->load('transaction', 'article');
        $article = $detail->article;
        $transaction = $detail->transaction;

        if (! $article || ! $transaction) {
            ArticlePrice::where('article_id', $detail->article_id)
                ->where('transaction_id', $detail->inventory_transaction_id)
                ->delete();

            $this->movementService->syncTransactionStatus($detail);

            return;
        }

        if ($transaction->status === 'inactive') {
            ArticlePrice::where('article_id', $article->id)
                ->where('transaction_id', $transaction->id)
                ->delete();

            return;
        }

        if ($this->isInReopenedMonth($transaction)) {
            ArticlePrice::where('article_id', $article->id)
                ->where('transaction_id', $transaction->id)
                ->delete();

            $this->movementService->syncTransactionStatus($detail);

            return;
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

        ArticlePrice::where('article_id', $article->id)
            ->where('transaction_id', $transaction->id)
            ->delete();

        $this->movementService->syncTransactionStatus($detail);

        $this->movementService->notifyIfStockCritical($article, $newStock);
    }

    private function isInReopenedMonth(InventoryTransaction $transaction): bool
    {
        if (! $transaction->transaction_date) {
            return false;
        }

        $date = Carbon::parse($transaction->transaction_date);

        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        return $closure && $closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');
    }
}
