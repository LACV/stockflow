<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Services\EntryDeletionService;
use App\Services\MonthlyMovementsExcelService;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EntryDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Update:Article', 'ViewAny:Article'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function article(string $code): Article
    {
        return Article::create(['name' => 'Art '.$code, 'code' => $code, 'stock_quantity' => 0, 'current_average_cost' => 0, 'is_active' => true]);
    }

    /** @param array<int, array{0: Article, 1: float, 2: float}> $lines [article, qty, cost] */
    private function saved(string $type, string $date, array $lines): InventoryTransaction
    {
        $tx = InventoryTransaction::create(['type' => $type, 'status' => 'inactive', 'transaction_date' => $date]);

        foreach ($lines as [$article, $qty, $cost]) {
            InventoryTransactionDetail::create([
                'inventory_transaction_id' => $tx->id, 'article_id' => $article->id,
                'quantity' => $qty, 'unit_cost' => $cost, 'total_cost' => $qty * $cost,
            ]);
        }

        $result = app(MovementService::class)->finalizeTransaction($tx);
        $this->assertTrue($result['ok'], $result['body']);

        return $tx->fresh();
    }

    private function service(): EntryDeletionService
    {
        return app(EntryDeletionService::class);
    }

    public function test_deleting_whole_entry_deletes_its_dependent_exit_and_recalculates_stock(): void
    {
        $a = $this->article('A');
        $entry = $this->saved('entry', '2026-10-02', [[$a, 5, 100]]);
        $exit = $this->saved('exit', '2026-10-05', [[$a, 2, 0]]);

        $plan = $this->service()->planEntryDeletion($entry);
        $this->assertNull($plan['blocked']);
        $this->assertCount(1, $plan['exits']);

        $this->service()->deleteEntry($entry);

        $this->assertNull(InventoryTransaction::find($entry->id));
        $this->assertNull(InventoryTransaction::find($exit->id), 'despacho que quedo sin lineas se elimina');
        $this->assertSame(0.0, (float) $a->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $a->fresh()->current_average_cost);
        $this->assertSame(0, ArticlePrice::where('article_id', $a->id)->count());
    }

    public function test_exit_still_covered_by_another_entry_is_kept(): void
    {
        $a = $this->article('A');
        $this->saved('entry', '2026-10-02', [[$a, 10, 100]]);
        $second = $this->saved('entry', '2026-10-03', [[$a, 5, 200]]);
        $exit = $this->saved('exit', '2026-10-05', [[$a, 3, 0]]);

        $plan = $this->service()->planEntryDeletion($second);
        $this->assertCount(0, $plan['exits']);

        $this->service()->deleteEntry($second);

        $this->assertNotNull(InventoryTransaction::find($exit->id));
        $this->assertSame(7.0, (float) $a->fresh()->stock_quantity);
        $this->assertEquals(100.0, (float) $a->fresh()->current_average_cost);
    }

    public function test_exit_that_loses_cover_is_deleted_even_if_stock_ends_positive(): void
    {
        $a = $this->article('A');
        $first = $this->saved('entry', '2026-10-02', [[$a, 5, 100]]);
        $exit = $this->saved('exit', '2026-10-03', [[$a, 4, 0]]);
        $this->saved('entry', '2026-10-10', [[$a, 10, 300]]);

        $plan = $this->service()->planEntryDeletion($first);
        $this->assertCount(1, $plan['exits'], 'el 03/10 ya no tendria stock en su momento');

        $this->service()->deleteEntry($first);

        $this->assertNull(InventoryTransaction::find($exit->id));
        $this->assertSame(10.0, (float) $a->fresh()->stock_quantity);
        $this->assertEquals(300.0, (float) $a->fresh()->current_average_cost);
    }

    public function test_deleting_one_product_of_an_entry_only_affects_that_product(): void
    {
        $a = $this->article('A');
        $b = $this->article('B');
        $entry = $this->saved('entry', '2026-10-02', [[$a, 5, 100], [$b, 7, 50]]);
        $exit = $this->saved('exit', '2026-10-05', [[$a, 2, 0], [$b, 1, 0]]);
        $detailA = $entry->details()->where('article_id', $a->id)->first();

        $plan = $this->service()->planDetailDeletion($detailA);
        $this->assertCount(1, $plan['exits']);

        $this->service()->deleteDetail($detailA);

        $this->assertSame(1, $entry->details()->count());
        $this->assertSame(1, $exit->details()->count(), 'la salida del otro producto queda');
        $this->assertSame('active', $exit->fresh()->status);
        $this->assertSame(0.0, (float) $a->fresh()->stock_quantity);
        $this->assertSame(6.0, (float) $b->fresh()->stock_quantity);
    }

    public function test_sole_product_of_an_entry_cannot_be_deleted_alone(): void
    {
        $a = $this->article('A');
        $entry = $this->saved('entry', '2026-10-02', [[$a, 5, 100]]);
        $detail = $entry->details()->first();

        $plan = $this->service()->planDetailDeletion($detail);

        $this->assertNotNull($plan['blocked']);
        $this->assertTrue($plan['requiresWholeEntry']);
        $this->expectException(\RuntimeException::class);
        $this->service()->deleteDetail($detail);
    }

    public function test_closed_month_blocks_everything(): void
    {
        $a = $this->article('A');
        $entry = $this->saved('entry', '2026-10-02', [[$a, 5, 100]]);
        MonthlyClosure::withoutEvents(fn () => MonthlyClosure::create(['month' => 10, 'year' => 2026, 'is_closed' => true, 'closed_at' => now()]));

        $plan = $this->service()->planEntryDeletion($entry);

        $this->assertNotNull($plan['blocked']);
        $this->expectException(\RuntimeException::class);
        $this->service()->deleteEntry($entry);
    }

    public function test_preview_values_reflect_the_deletion(): void
    {
        $a = $this->article('A');
        $entry = $this->saved('entry', '2026-10-02', [[$a, 5, 100]]);
        $this->saved('exit', '2026-10-05', [[$a, 2, 0]]);
        $excel = app(MonthlyMovementsExcelService::class);
        $before = collect($excel->previewArrays(10, 2026)['salidas'])->filter(fn ($r) => ($r[0] ?? null) === 'A')->count();

        $this->service()->deleteEntry($entry);

        $after = collect($excel->previewArrays(10, 2026)['salidas'])->filter(fn ($r) => ($r[0] ?? null) === 'A')->count();
        $this->assertSame(1, $before);
        $this->assertSame(0, $after);
    }

    public function test_entry_without_exits_is_deleted_plainly(): void
    {
        $a = $this->article('A');
        $entry = $this->saved('entry', '2026-10-02', [[$a, 5, 100]]);

        $plan = $this->service()->planEntryDeletion($entry);
        $this->assertCount(0, $plan['exits']);

        $this->service()->deleteEntry($entry);

        $this->assertNull(InventoryTransaction::find($entry->id));
        $this->assertSame(0.0, (float) $a->fresh()->stock_quantity);
    }
}
