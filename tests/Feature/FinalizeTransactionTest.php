<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Services\MonthlyMovementsExcelService;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinalizeTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function article(float $stock): Article
    {
        return Article::create(['name' => 'Guantes', 'code' => 'G-1', 'stock_quantity' => $stock, 'current_average_cost' => 100, 'is_active' => true]);
    }

    private function draftExit(Article $article, float $qty, string $date = '2026-10-14'): InventoryTransaction
    {
        $tx = InventoryTransaction::create(['type' => 'exit', 'status' => 'inactive', 'transaction_date' => $date]);
        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $tx->id, 'article_id' => $article->id,
            'quantity' => $qty, 'unit_cost' => 100, 'total_cost' => $qty * 100,
        ]);

        return $tx;
    }

    public function test_finalize_activates_draft_and_discounts_stock(): void
    {
        $article = $this->article(10);
        $tx = $this->draftExit($article, 3);

        $result = app(MovementService::class)->finalizeTransaction($tx);

        $this->assertTrue($result['ok']);
        $this->assertSame('active', $tx->fresh()->status);
        $this->assertSame(7.0, (float) $article->fresh()->stock_quantity);
    }

    public function test_finalize_refuses_when_stock_is_insufficient(): void
    {
        $article = $this->article(2);
        $tx = $this->draftExit($article, 3);

        $result = app(MovementService::class)->finalizeTransaction($tx);

        $this->assertFalse($result['ok']);
        $this->assertSame('inactive', $tx->fresh()->status);
        $this->assertSame(2.0, (float) $article->fresh()->stock_quantity);
    }

    public function test_finalize_refuses_when_month_is_closed(): void
    {
        $article = $this->article(10);
        $tx = $this->draftExit($article, 3);
        MonthlyClosure::withoutEvents(fn () => MonthlyClosure::create(['month' => 10, 'year' => 2026, 'is_closed' => true, 'closed_at' => now()]));

        $result = app(MovementService::class)->finalizeTransaction($tx);

        $this->assertFalse($result['ok']);
        $this->assertSame('inactive', $tx->fresh()->status);
        $this->assertSame(10.0, (float) $article->fresh()->stock_quantity);
    }

    public function test_finalize_refuses_empty_draft(): void
    {
        $tx = InventoryTransaction::create(['type' => 'exit', 'status' => 'inactive', 'transaction_date' => '2026-10-14']);

        $result = app(MovementService::class)->finalizeTransaction($tx);

        $this->assertFalse($result['ok']);
        $this->assertSame('inactive', $tx->fresh()->status);
    }

    public function test_finalize_is_noop_when_already_active(): void
    {
        $article = $this->article(10);
        $tx = $this->draftExit($article, 3);
        $service = app(MovementService::class);
        $service->finalizeTransaction($tx);

        $result = $service->finalizeTransaction($tx->fresh());

        $this->assertFalse($result['ok']);
        $this->assertSame(7.0, (float) $article->fresh()->stock_quantity, 'No debe descontar dos veces');
    }

    public function test_draft_summary_counts_only_drafts_with_lines_in_the_month(): void
    {
        $article = $this->article(10);
        $this->draftExit($article, 3, '2026-10-14');
        $this->draftExit($article, 2, '2026-10-20');
        InventoryTransaction::create(['type' => 'exit', 'status' => 'inactive', 'transaction_date' => '2026-10-22']);
        $this->draftExit($article, 5, '2026-09-10');

        $summary = app(MonthlyMovementsExcelService::class)->draftSummary(10, 2026);

        $this->assertSame(2, $summary['count']);
        $this->assertEquals(500.0, $summary['value']);
    }
}
