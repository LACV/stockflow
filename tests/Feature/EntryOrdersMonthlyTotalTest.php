<?php

namespace Tests\Feature;

use App\Filament\Resources\EntryOrders\Pages\ListEntryOrders;
use App\Models\Article;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EntryOrdersMonthlyTotalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Update:Article', 'ViewAny:Article', 'ViewAny:EntryOrder', 'Create:EntryOrder'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    public function test_list_page_shows_monthly_total_of_active_entries_only(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(['ViewAny:EntryOrder', 'Create:EntryOrder']);
        $this->actingAs($user);

        MonthlyClosure::create(['month' => 7, 'year' => 2026, 'is_closed' => false]);

        $article = Article::create([
            'name' => 'Guantes',
            'code' => 'G-1',
            'stock_quantity' => 0,
            'current_average_cost' => 100,
            'is_active' => true,
        ]);

        $makeDetail = function (string $type, string $status, string $date, float $totalCost) use ($article): void {
            $transaction = InventoryTransaction::create([
                'type' => $type,
                'status' => $status,
                'transaction_date' => $date,
            ]);

            InventoryTransactionDetail::create([
                'inventory_transaction_id' => $transaction->id,
                'article_id' => $article->id,
                'quantity' => 1,
                'unit_cost' => $totalCost,
                'total_cost' => $totalCost,
            ]);
        };

        $makeDetail('entry', 'active', '2026-07-15', 300);
        $makeDetail('entry', 'inactive', '2026-07-16', 999);
        $makeDetail('exit', 'active', '2026-07-17', 555);
        $makeDetail('entry', 'active', '2026-06-10', 777);

        $response = $this->get(route('filament.admin.resources.entry-orders.index'));

        $response->assertOk();
        $response->assertSee('Total julio 2026');
        $response->assertSee('$ 300.00');
        $response->assertDontSee('$ 999.00');
        $response->assertDontSee('$ 555.00');
        $response->assertDontSee('$ 777.00');
        $response->assertSee('Crear Entrada');
    }

    public function test_total_mes_without_month_filter_sums_all_history(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user);

        MonthlyClosure::create(['month' => 7, 'year' => 2026, 'is_closed' => false]);

        $article = Article::create([
            'name' => 'Guantes',
            'code' => 'G-2',
            'stock_quantity' => 0,
            'current_average_cost' => 100,
            'is_active' => true,
        ]);

        $makeDetail = function (string $date, float $totalCost) use ($article): void {
            $transaction = InventoryTransaction::create([
                'type' => 'entry',
                'status' => 'active',
                'transaction_date' => $date,
            ]);

            InventoryTransactionDetail::create([
                'inventory_transaction_id' => $transaction->id,
                'article_id' => $article->id,
                'quantity' => 1,
                'unit_cost' => $totalCost,
                'total_cost' => $totalCost,
            ]);
        };

        $makeDetail('2026-06-10', 400);
        $makeDetail('2026-07-15', 300);

        $page = new ListEntryOrders;
        $page->tableFilters = null;

        $data = $page->getTotalMes();

        $this->assertSame('Total Histórico', $data['label']);
        $this->assertEquals(700.0, $data['total']);
    }
}
