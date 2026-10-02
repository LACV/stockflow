<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Models\MonthlyClosureBalance;
use App\Models\Type;
use App\Services\MonthlyClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyClosureSnapshotChainTest extends TestCase
{
    use RefreshDatabase;

    private function makeClosure(int $month, int $year, bool $closed): MonthlyClosure
    {
        return MonthlyClosure::withoutEvents(fn () => MonthlyClosure::create([
            'month' => $month,
            'year' => $year,
            'is_closed' => $closed,
            'closed_at' => $closed ? "{$year}-{$month}-28 23:59:59" : null,
        ]));
    }

    private static int $codeSeq = 0;

    private function makeArticle(float $stock, float $ppp): Article
    {
        self::$codeSeq++;

        return Article::create([
            'name' => 'Tuerca',
            'code' => 'A-1'.str_pad((string) self::$codeSeq, 3, '0', STR_PAD_LEFT),
            'type_id' => Type::create(['name' => 'REPUESTOS'])->id,
            'stock_quantity' => $stock,
            'current_average_cost' => $ppp,
            'is_active' => true,
        ]);
    }

    public function test_snapshot_chains_from_previous_closed_month_for_articles_without_balance(): void
    {
        $article = $this->makeArticle(999, 999);

        $jan = $this->makeClosure(1, 2026, true);
        $feb = $this->makeClosure(2, 2026, true);

        MonthlyClosureBalance::create([
            'monthly_closure_id' => $jan->id,
            'article_id' => $article->id,
            'stock' => 5,
            'average_cost' => 100,
            'total_value' => 500,
        ]);

        $service = app(MonthlyClosureService::class);
        $service->regenerateSnapshot($jan);
        $service->regenerateSnapshot($feb);

        $febRow = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $feb->id)
            ->where('article_id', $article->id)
            ->first();

        $this->assertNotNull($febRow);
        $this->assertEquals(5.0, (float) $febRow->stock_quantity);
        $this->assertEquals(100.0, (float) $febRow->average_cost);
    }

    public function test_snapshot_first_month_falls_back_to_live_values(): void
    {
        $article = $this->makeArticle(7, 50);

        $jan = $this->makeClosure(1, 2026, true);

        $service = app(MonthlyClosureService::class);
        $service->regenerateSnapshot($jan);

        $row = DB::table('monthly_closure_snapshots')
            ->where('monthly_closure_id', $jan->id)
            ->where('article_id', $article->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(7.0, (float) $row->stock_quantity);
        $this->assertEquals(50.0, (float) $row->average_cost);
    }

    public function test_recalcular_ppp_resets_average_cost_when_stock_is_depleted(): void
    {
        $article = $this->makeArticle(0, 0);

        $this->addDetail('entry', '2026-01-05', 10, 100);
        $this->addDetail('exit', '2026-01-06', 10, 0);

        Artisan::call('inventario:recalcular-ppp');

        $article->refresh();
        $this->assertEquals(0.0, (float) $article->stock_quantity);
        $this->assertEquals(0.0, (float) $article->current_average_cost);
    }

    public function test_recalcular_ppp_orders_by_transaction_date_not_creation(): void
    {
        $article = $this->makeArticle(0, 0);

        $t1 = $this->addDetail('entry', '2026-01-01', 10, 100);
        $t2 = $this->addDetail('exit', '2026-01-02', 10, 0);
        $t3 = $this->addDetail('entry', '2026-01-03', 5, 300);

        // Fuerza un orden de created_at inverso al de las fechas de transacción
        DB::table('inventory_transaction_details')
            ->where('id', $t1)
            ->update(['created_at' => '2026-01-03 10:00:00']);
        DB::table('inventory_transaction_details')
            ->where('id', $t2)
            ->update(['created_at' => '2026-01-02 10:00:00']);
        DB::table('inventory_transaction_details')
            ->where('id', $t3)
            ->update(['created_at' => '2026-01-01 10:00:00']);

        Artisan::call('inventario:recalcular-ppp');

        $article->refresh();
        $this->assertEquals(5.0, (float) $article->stock_quantity);
        $this->assertEquals(300.0, (float) $article->current_average_cost);
    }

    public function test_zero_stale_ppp_on_depleted_tracked_articles(): void
    {
        $tracked = $this->makeArticle(0, 0);
        $this->addDetail('entry', '2026-01-05', 5, 100);
        $this->addDetail('exit', '2026-01-06', 5, 0);

        $untracked = $this->makeArticle(0, 999);
        $legacy = $this->makeArticle(0, 0); // sin movimientos, ppp ya 0

        // Simula un PPP obsoleto en un artículo agotado pero con historial
        DB::table('articles')->where('id', $tracked->id)->update(['current_average_cost' => 100]);

        $zeroed = app(MonthlyClosureService::class)->zeroStalePppOnDepleted();

        $this->assertEquals(1, $zeroed);
        $this->assertSame(0.0, (float) DB::table('articles')->where('id', $tracked->id)->value('current_average_cost'));
        $this->assertSame(999.0, (float) DB::table('articles')->where('id', $untracked->id)->value('current_average_cost'));
        $this->assertSame(0.0, (float) DB::table('articles')->where('id', $legacy->id)->value('current_average_cost'));
    }

    private function addDetail(string $type, string $date, float $qty, float $unitCost): int
    {
        $tx = InventoryTransaction::withoutEvents(fn () => InventoryTransaction::create([
            'type' => $type,
            'status' => 'active',
            'transaction_date' => $date,
        ]));

        $detail = InventoryTransactionDetail::withoutEvents(fn () => InventoryTransactionDetail::create([
            'inventory_transaction_id' => $tx->id,
            'article_id' => $this->lastArticle()->id,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $qty * $unitCost,
        ]));

        return (int) $detail->id;
    }

    private function lastArticle(): Article
    {
        return Article::latest('id')->first();
    }
}
