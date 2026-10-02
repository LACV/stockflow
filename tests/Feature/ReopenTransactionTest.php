<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticlePrice;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\Provider;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReopenTransactionTest extends TestCase
{
    use RefreshDatabase;

    private MovementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MovementService::class);
    }

    public function test_revert_entry_restores_stock_and_removes_prices(): void
    {
        $article = Article::create([
            'name' => 'Mascarilla',
            'code' => 'M-1',
            'stock_quantity' => 5,
            'current_average_cost' => 100,
            'is_active' => true,
        ]);

        $provider = Provider::create([
            'provider' => 'Prov',
            'status' => true,
        ]);

        $transaction = InventoryTransaction::create([
            'type' => 'entry',
            'status' => 'active',
            'transaction_date' => now()->toDateString(),
            'provider_id' => $provider->id,
            'month' => now()->month,
            'year' => now()->year,
        ]);

        $detail = InventoryTransactionDetail::withoutEvents(fn () => InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $article->id,
            'quantity' => 3,
            'unit_cost' => 200,
            'total_cost' => 300,
        ]));

        $article->stock_quantity = 10;
        $article->saveQuietly();

        ArticlePrice::create([
            'article_id' => $article->id,
            'transaction_id' => $transaction->id,
            'quantity' => 3,
            'price' => 100,
            'date' => $transaction->transaction_date,
            'source' => 'ENTRY',
        ]);

        $this->service->revertTransactionStock($transaction);

        $this->assertEquals(7, (float) $article->fresh()->stock_quantity);
        $this->assertDatabaseMissing('article_prices', [
            'article_id' => $article->id,
            'transaction_id' => $transaction->id,
        ]);
        $this->assertNotNull($detail->fresh());
    }

    public function test_revert_exit_adds_stock_back(): void
    {
        $article = Article::create([
            'stock_quantity' => 2,
            'current_average_cost' => 50,
            'is_active' => true,
            'code' => 'E-1',
            'name' => 'Egreso',
        ]);

        $transaction = InventoryTransaction::create([
            'type' => 'exit',
            'status' => 'active',
            'transaction_date' => now()->toDateString(),
            'month' => now()->month,
            'year' => now()->year,
        ]);

        InventoryTransactionDetail::withoutEvents(fn () => InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $article->id,
            'quantity' => 2,
            'unit_cost' => 50,
            'total_cost' => 100,
        ]));

        $this->service->revertTransactionStock($transaction);

        $this->assertSame(4.0, (float) $article->fresh()->stock_quantity);
    }
}
