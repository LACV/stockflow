<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\InventoryTransactionDetail;
use App\Services\MovementService;
use PHPUnit\Framework\TestCase;

class MovementServiceTest extends TestCase
{
    private MovementService $service;

    protected function setUp(): void
    {
        $this->service = new MovementService;
    }

    public function test_entry_increases_stock_and_updates_ppp(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('ENTRY', 10, 100, 5, 200);

        $this->assertEquals(15.0, $stock);
        $this->assertEquals((10 * 100 + 5 * 200) / 15, $ppp);
        $this->assertEmpty($updates);
    }

    public function test_entry_from_zero_stock(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('ENTRY', 0, 0, 10, 50);

        $this->assertEquals(10.0, $stock);
        $this->assertEquals(50.0, $ppp);
        $this->assertEmpty($updates);
    }

    public function test_entry_with_stock_zero_and_previous_ppp(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('ENTRY', 0, 100, 5, 50);

        $this->assertEquals(5.0, $stock);
        $this->assertEquals(50.0, $ppp);
        $this->assertEmpty($updates);
    }

    public function test_exit_decreases_stock_and_sets_unit_cost_to_ppp(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('EXIT', 100, 50, 10, 999);

        $this->assertEquals(90.0, $stock);
        $this->assertEquals(50.0, $ppp);
        $this->assertArrayHasKey('unit_cost', $updates);
        $this->assertEquals(50.0, $updates['unit_cost']);
        $this->assertArrayHasKey('total_cost', $updates);
        $this->assertEquals(500.0, $updates['total_cost']);
    }

    public function test_exit_entire_stock(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('EXIT', 10, 100, 10, 999);

        $this->assertEquals(0.0, $stock);
        $this->assertEquals(100.0, $ppp);
        $this->assertEquals(100.0, $updates['unit_cost']);
        $this->assertEquals(1000.0, $updates['total_cost']);
    }

    public function test_positive_adjustment_increases_stock_and_updates_ppp(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('ADJUSTMENT', 20, 80, 5, 100);

        $this->assertEquals(25.0, $stock);
        $this->assertEquals((20 * 80 + 5 * 100) / 25, $ppp);
        $this->assertEmpty($updates);
    }

    public function test_negative_adjustment_decreases_stock_and_sets_unit_cost_to_ppp(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('ADJUSTMENT', 50, 60, -10, 999);

        $this->assertEquals(40.0, $stock);
        $this->assertEquals(60.0, $ppp);
        $this->assertArrayHasKey('unit_cost', $updates);
        $this->assertEquals(60.0, $updates['unit_cost']);
        $this->assertArrayHasKey('total_cost', $updates);
        $this->assertEquals(600.0, $updates['total_cost']);
    }

    public function test_negative_adjustment_entire_stock(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('ADJUSTMENT', 10, 100, -10, 999);

        $this->assertEquals(0.0, $stock);
        $this->assertEquals(100.0, $ppp);
        $this->assertEquals(100.0, $updates['unit_cost']);
        $this->assertEquals(1000.0, $updates['total_cost']);
    }

    public function test_entry_case_insensitive(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('entry', 5, 10, 3, 20);

        $this->assertEquals(8.0, $stock);
        $this->assertEquals((5 * 10 + 3 * 20) / 8, $ppp);
    }

    public function test_exit_with_precision_values(): void
    {
        [$stock, $ppp, $updates] = $this->service->applyMovement('EXIT', 100.5, 45.25, 10.75, 999);

        $expectedStock = 100.5 - 10.75;
        $this->assertEquals($expectedStock, $stock);
        $this->assertEquals(45.25, $ppp);
        $this->assertEquals(45.25, $updates['unit_cost']);
        $this->assertEquals(10.75 * 45.25, $updates['total_cost']);
    }

    private function detail(int $articleId, float $quantity, float $stock): InventoryTransactionDetail
    {
        $article = new Article(['id' => $articleId, 'code' => "C{$articleId}", 'name' => "Artículo {$articleId}", 'stock_quantity' => $stock]);
        $detail = new InventoryTransactionDetail(['article_id' => $articleId, 'quantity' => $quantity]);
        $detail->setRelation('article', $article);

        return $detail;
    }

    public function test_exit_blocks_when_stock_insufficient(): void
    {
        $insuficientes = $this->service->validarStockSuficiente(collect([$this->detail(1, 10, 5)]), 'exit');

        $this->assertCount(1, $insuficientes);
        $this->assertStringContainsString('disponible 5 und', $insuficientes[0]);
    }

    public function test_exit_allows_when_stock_sufficient(): void
    {
        $this->assertEmpty($this->service->validarStockSuficiente(collect([$this->detail(1, 3, 5)]), 'exit'));
    }

    public function test_exit_sums_quantities_per_article(): void
    {
        $details = collect([
            $this->detail(1, 4, 5),
            $this->detail(1, 2, 5),
        ]);

        $this->assertCount(1, $this->service->validarStockSuficiente($details, 'exit'));
    }

    public function test_negative_adjustment_blocks_when_stock_insufficient(): void
    {
        $insuficientes = $this->service->validarStockSuficiente(collect([$this->detail(1, -7, 5)]), 'adjustment');

        $this->assertCount(1, $insuficientes);
    }

    public function test_positive_adjustment_never_blocks(): void
    {
        $this->assertEmpty($this->service->validarStockSuficiente(collect([$this->detail(1, 7, 5)]), 'adjustment'));
    }

    public function test_entry_never_blocks(): void
    {
        $this->assertEmpty($this->service->validarStockSuficiente(collect([$this->detail(1, 999, 5)]), 'entry'));
    }
}
