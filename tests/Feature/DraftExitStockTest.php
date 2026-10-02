<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftExitStockTest extends TestCase
{
    use RefreshDatabase;

    private function makeArticle(float $stock = 500): Article
    {
        return Article::create([
            'name' => 'Guantes',
            'code' => 'G-1',
            'stock_quantity' => $stock,
            'current_average_cost' => 100,
            'is_active' => true,
        ]);
    }

    private function makeDraftExit(Article $article, float $qty): InventoryTransaction
    {
        $transaction = InventoryTransaction::create([
            'type' => 'exit',
            'status' => 'inactive',
            'transaction_date' => now()->toDateString(),
        ]);

        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $article->id,
            'quantity' => $qty,
            'unit_cost' => 100,
            'total_cost' => $qty * 100,
        ]);

        return $transaction;
    }

    public function test_draft_exit_details_do_not_modify_stock(): void
    {
        $article = $this->makeArticle(500);

        $this->makeDraftExit($article, 100);

        $this->assertSame(500.0, (float) $article->fresh()->stock_quantity);
    }

    public function test_deleting_draft_keeps_stock_untouched(): void
    {
        $article = $this->makeArticle(500);
        $draft = $this->makeDraftExit($article, 100);

        $draft->delete();

        $this->assertSame(500.0, (float) $article->fresh()->stock_quantity);
        $this->assertDatabaseMissing('inventory_transaction_details', [
            'inventory_transaction_id' => $draft->id,
        ]);
    }

    public function test_pending_effective_quantity_counts_rows_and_adjustments_without_changing_stock(): void
    {
        $article = $this->makeArticle(500);

        $transaction = InventoryTransaction::create([
            'type' => 'exit',
            'status' => 'inactive',
            'transaction_date' => now()->toDateString(),
        ]);

        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $article->id,
            'quantity' => 100,
            'unit_cost' => 100,
            'total_cost' => 100 * 100,
        ]);

        $second = InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $article->id,
            'quantity' => 40,
            'unit_cost' => 100,
            'total_cost' => 40 * 100,
        ]);

        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $article->id,
            'quantity' => 10,
            'unit_cost' => 100,
            'total_cost' => 10 * 100,
            'adjusts_detail_id' => $second->id,
        ]);

        $this->assertSame(500.0, (float) $article->fresh()->stock_quantity);

        $pending = $transaction->details()
            ->with('adjustments')
            ->get()
            ->reject(fn ($detail) => filled($detail->adjusts_detail_id))
            ->groupBy('article_id')
            ->map(fn ($rows) => $rows->sum(fn ($detail) => (float) $detail->quantity + (float) $detail->adjustments->sum('quantity')))
            ->toArray();

        $effectivePending = (float) ($pending[$article->id] ?? 0);
        $projected = max(0.0, 500.0 - $effectivePending);

        $this->assertSame(150.0, $effectivePending);
        $this->assertSame(350.0, $projected);
    }

    public function test_deleting_one_detail_from_draft_keeps_transaction_inactive(): void
    {
        $article = $this->makeArticle(500);

        $draft = InventoryTransaction::create([
            'type' => 'exit',
            'status' => 'inactive',
            'transaction_date' => now()->toDateString(),
        ]);

        $first = InventoryTransactionDetail::create([
            'inventory_transaction_id' => $draft->id,
            'article_id' => $article->id,
            'quantity' => 100,
            'unit_cost' => 100,
            'total_cost' => 100 * 100,
        ]);

        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $draft->id,
            'article_id' => $article->id,
            'quantity' => 40,
            'unit_cost' => 100,
            'total_cost' => 40 * 100,
        ]);

        $first->delete();

        $this->assertSame('inactive', $draft->fresh()->status);
        $this->assertSame(500.0, (float) $article->fresh()->stock_quantity);
    }
}
