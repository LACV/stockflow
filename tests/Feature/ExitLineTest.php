<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ExitLineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Update:Article', 'ViewAny:Article'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function article(float $stock = 7, string $code = 'A'): Article
    {
        return Article::create(['name' => 'Art '.$code, 'code' => $code, 'stock_quantity' => $stock, 'current_average_cost' => 100, 'is_active' => true]);
    }

    private function dispatch(string $status, string $date = '2026-10-30'): InventoryTransaction
    {
        return InventoryTransaction::create(['type' => 'exit', 'status' => $status, 'transaction_date' => $date]);
    }

    private function add(InventoryTransaction $tx, Article $article, float $qty): InventoryTransactionDetail
    {
        return app(MovementService::class)->addExitLine($tx, [
            'article_id' => $article->id, 'quantity' => $qty, 'unit_cost' => 100,
        ]);
    }

    public function test_line_in_an_active_dispatch_discounts_stock_immediately(): void
    {
        $a = $this->article(7);
        $tx = $this->dispatch('active');

        $line = $this->add($tx, $a, 2);

        $this->assertSame(5.0, (float) $a->fresh()->stock_quantity);
        $this->assertSame(1, ArticlePrice::where('transaction_id', $tx->id)->where('article_id', $a->id)->count());
        $this->assertEquals(200.0, (float) $line->fresh()->total_cost);
    }

    public function test_line_beyond_the_stock_is_refused_and_nothing_is_written(): void
    {
        $a = $this->article(7);
        $tx = $this->dispatch('active');

        try {
            $this->add($tx, $a, 8);
            $this->fail('Debio rechazar la linea');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $this->assertSame(7.0, (float) $a->fresh()->stock_quantity);
        $this->assertSame(0, $tx->details()->count());
    }

    public function test_second_dispatch_cannot_take_what_the_first_already_took(): void
    {
        $a = $this->article(7);
        $first = $this->dispatch('active', '2026-10-29');
        $second = $this->dispatch('active', '2026-10-31');

        $this->add($first, $a, 7);

        $this->expectException(ValidationException::class);
        $this->add($second, $a, 5);
    }

    public function test_stock_reserved_by_a_reopened_dispatch_cannot_be_taken(): void
    {
        $a = $this->article(7);
        $reopened = $this->dispatch('inactive', '2026-10-29');
        $this->add($reopened, $a, 5);
        $other = $this->dispatch('active', '2026-10-31');

        $this->add($other, $a, 2);

        $this->expectException(ValidationException::class);
        $this->add($other, $a, 1);
    }

    public function test_line_in_a_draft_checks_but_does_not_discount(): void
    {
        $a = $this->article(7);
        $draft = $this->dispatch('inactive');

        $this->add($draft, $a, 4);

        $this->assertSame(7.0, (float) $a->fresh()->stock_quantity);

        $this->expectException(ValidationException::class);
        $this->add($draft, $a, 4);
    }

    public function test_closed_month_refuses_the_line(): void
    {
        $a = $this->article(7);
        $tx = $this->dispatch('active');
        MonthlyClosure::withoutEvents(fn () => MonthlyClosure::create(['month' => 10, 'year' => 2026, 'is_closed' => true, 'closed_at' => now()]));

        try {
            $this->add($tx, $a, 1);
            $this->fail('Debio rechazar por mes cerrado');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }

        $this->assertSame(7.0, (float) $a->fresh()->stock_quantity);
    }

    public function test_a_return_reduces_the_net_exit_and_an_extra_removal_increases_it(): void
    {
        $service = app(MovementService::class);

        $this->assertEquals(4.0, $service->netExitQuantity(5, 1), 'devolver 1 unidad: sale 4');
        $this->assertEquals(6.0, $service->netExitQuantity(5, -1), 'quitar 1 mas: sale 6');
        $this->assertEquals(5.0, $service->netExitQuantity(5, 0));
    }
}
