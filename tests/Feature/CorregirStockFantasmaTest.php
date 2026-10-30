<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorregirStockFantasmaTest extends TestCase
{
    use RefreshDatabase;

    private function makeArticle(float $stock, float $cost = 0): Article
    {
        return Article::create([
            'name' => 'Compresor',
            'code' => 'C-1',
            'stock_quantity' => $stock,
            'current_average_cost' => $cost,
            'is_active' => true,
        ]);
    }

    public function test_zeroes_stock_of_article_without_backing(): void
    {
        $article = $this->makeArticle(1);

        $this->artisan('inventario:corregir-stock-fantasma', ['codigo' => 'C-1'])->assertSuccessful();

        $this->assertSame(0.0, (float) $article->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $article->fresh()->current_average_cost);
    }

    public function test_dry_run_does_not_write(): void
    {
        $article = $this->makeArticle(1);

        $this->artisan('inventario:corregir-stock-fantasma', ['codigo' => 'C-1', '--dry-run' => true])->assertSuccessful();

        $this->assertSame(1.0, (float) $article->fresh()->stock_quantity);
    }

    public function test_refuses_article_with_active_movements(): void
    {
        $article = $this->makeArticle(0, 0);
        $tx = InventoryTransaction::create(['type' => 'entry', 'status' => 'active', 'transaction_date' => now()->toDateString()]);
        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $tx->id, 'article_id' => $article->id,
            'quantity' => 5, 'unit_cost' => 100, 'total_cost' => 500,
        ]);

        $this->artisan('inventario:corregir-stock-fantasma', ['codigo' => 'C-1'])->assertFailed();

        $this->assertSame(5.0, (float) $article->fresh()->stock_quantity);
    }

    public function test_refuses_article_with_legacy_price_backing_its_stock(): void
    {
        $article = $this->makeArticle(1, 246369.56);
        ArticlePrice::create([
            'article_id' => $article->id, 'quantity' => 1, 'price' => 246369.56,
            'date' => '2024-08-14', 'source' => 'migration', 'is_active' => true,
        ]);

        $this->artisan('inventario:corregir-stock-fantasma', ['codigo' => 'C-1'])->assertFailed();

        $this->assertSame(1.0, (float) $article->fresh()->stock_quantity);
    }
}
