<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftReservationTest extends TestCase
{
    use RefreshDatabase;

    private function article(string $code = 'A'): Article
    {
        return Article::create(['name' => 'Art '.$code, 'code' => $code, 'stock_quantity' => 7, 'current_average_cost' => 100, 'is_active' => true]);
    }

    private function dispatch(string $status, string $date, array $lines, string $type = 'exit'): InventoryTransaction
    {
        $tx = InventoryTransaction::create(['type' => $type, 'status' => $status, 'transaction_date' => $date]);

        foreach ($lines as [$article, $qty]) {
            InventoryTransactionDetail::withoutEvents(fn () => InventoryTransactionDetail::create([
                'inventory_transaction_id' => $tx->id, 'article_id' => $article->id,
                'quantity' => $qty, 'unit_cost' => 100, 'total_cost' => $qty * 100,
            ]));
        }

        return $tx;
    }

    private function reserved(?int $exclude = null): array
    {
        return app(MovementService::class)->reservedByOtherDrafts($exclude);
    }

    public function test_quantities_in_other_drafts_are_reserved(): void
    {
        $a = $this->article();
        $this->dispatch('inactive', '2026-10-29', [[$a, 7]]);
        $mine = $this->dispatch('inactive', '2026-10-31', [[$a, 5]]);

        $this->assertEquals(7.0, $this->reserved($mine->id)[$a->id]);
    }

    public function test_own_draft_is_not_counted_against_itself(): void
    {
        $a = $this->article();
        $mine = $this->dispatch('inactive', '2026-10-29', [[$a, 7]]);

        $this->assertArrayNotHasKey($a->id, $this->reserved($mine->id));
    }

    public function test_active_dispatches_and_entries_do_not_reserve(): void
    {
        $a = $this->article();
        $this->dispatch('active', '2026-10-29', [[$a, 3]]);
        $this->dispatch('inactive', '2026-10-30', [[$a, 4]], 'entry');

        $this->assertArrayNotHasKey($a->id, $this->reserved());
    }

    public function test_drafts_in_a_closed_month_do_not_reserve(): void
    {
        $a = $this->article();
        $this->dispatch('inactive', '2026-09-10', [[$a, 3]]);
        MonthlyClosure::withoutEvents(fn () => MonthlyClosure::create(['month' => 9, 'year' => 2026, 'is_closed' => true, 'closed_at' => now()]));

        $this->assertArrayNotHasKey($a->id, $this->reserved());
    }

    public function test_several_drafts_add_up_per_article(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $this->dispatch('inactive', '2026-10-28', [[$a, 2], [$b, 1]]);
        $this->dispatch('inactive', '2026-10-29', [[$a, 3]]);

        $reserved = $this->reserved();

        $this->assertEquals(5.0, $reserved[$a->id]);
        $this->assertEquals(1.0, $reserved[$b->id]);
    }
}
