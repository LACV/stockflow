<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Destination;
use App\Models\Employee;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Services\MovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GuardarBorradoresPendientesTest extends TestCase
{
    use RefreshDatabase;

    private Destination $dest;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['Update:Article', 'ViewAny:Article'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->dest = Destination::create(['code' => 'AR-1', 'name' => 'Area 1']);
        $this->emp = Employee::create(['name' => 'Juan', 'status' => true]);
    }

    private function article(string $code, float $stock): Article
    {
        return Article::create(['name' => 'Art '.$code, 'code' => $code, 'stock_quantity' => $stock, 'current_average_cost' => 100, 'is_active' => true]);
    }

    private function activeDispatch(string $date, Article $article, float $qty, ?Employee $emp = null): InventoryTransaction
    {
        $tx = InventoryTransaction::create(['type' => 'exit', 'status' => 'active', 'transaction_date' => $date]);
        app(MovementService::class)->addExitLine($tx, [
            'article_id' => $article->id, 'quantity' => $qty, 'unit_cost' => 100,
            'destination_id' => $this->dest->id, 'employee_id' => ($emp ?? $this->emp)->id,
        ]);

        return $tx;
    }

    /** @param array<int, array{0: Article, 1: float, 2?: ?Employee}> $lines */
    private function draft(string $date, array $lines): InventoryTransaction
    {
        $tx = InventoryTransaction::create(['type' => 'exit', 'status' => 'inactive', 'transaction_date' => $date]);

        foreach ($lines as $line) {
            InventoryTransactionDetail::create([
                'inventory_transaction_id' => $tx->id, 'article_id' => $line[0]->id,
                'quantity' => $line[1], 'unit_cost' => 100, 'total_cost' => $line[1] * 100,
                'destination_id' => $this->dest->id, 'employee_id' => ($line[2] ?? $this->emp)->id,
            ]);
        }

        return $tx;
    }

    private function exec(array $ids, bool $dry = false): PendingCommand
    {
        return $this->artisan('inventario:guardar-borradores-pendientes', ['--ids' => implode(',', $ids)] + ($dry ? ['--dry-run' => true] : []));
    }

    public function test_requires_explicit_ids(): void
    {
        $this->artisan('inventario:guardar-borradores-pendientes')->assertFailed();
    }

    public function test_dry_run_writes_nothing(): void
    {
        $a = $this->article('A', 0);
        $draft = $this->draft('2026-10-02', [[$a, 1]]);

        $this->exec([$draft->id], dry: true)->assertSuccessful();

        $this->assertSame('inactive', $draft->fresh()->status);
        $this->assertSame(1, $draft->details()->count());
    }

    public function test_line_already_registered_elsewhere_is_removed_and_the_rest_is_saved_without_stock(): void
    {
        $dup = $this->article('DUP', 2);
        $missing = $this->article('MISS', 0);
        $this->activeDispatch('2026-10-03', $dup, 2);
        $draft = $this->draft('2026-10-02', [[$dup, 2], [$missing, 1]]);

        $this->exec([$draft->id])->assertSuccessful();

        $draft = $draft->fresh();
        $this->assertSame('active', $draft->status);
        $this->assertSame(1, $draft->details()->count());
        $this->assertSame($missing->id, $draft->details()->first()->article_id);
        $this->assertSame(0.0, (float) $dup->fresh()->stock_quantity, 'la duplicada no descuenta de nuevo');
        $this->assertSame(0.0, (float) $missing->fresh()->stock_quantity, 'sin stock: queda en 0, no negativo');
    }

    public function test_ppp_of_the_articles_is_never_touched(): void
    {
        $dup = $this->article('DUP', 2);
        $missing = $this->article('MISS', 0);
        $stocked = $this->article('OK', 5);
        $this->activeDispatch('2026-10-03', $dup, 2);
        $draft = $this->draft('2026-10-02', [[$dup, 2], [$missing, 1], [$stocked, 2]]);

        $this->exec([$draft->id])->assertSuccessful();

        foreach ([$dup, $missing, $stocked] as $article) {
            $this->assertEquals(100.0, (float) $article->fresh()->current_average_cost, $article->code);
        }
        $this->assertEquals(100.0, (float) $draft->details()->where('article_id', $missing->id)->first()->unit_cost, 'la linea toma el costo promedio del articulo');
    }

    public function test_line_with_stock_is_saved_normally(): void
    {
        $stocked = $this->article('OK', 5);
        $draft = $this->draft('2026-10-02', [[$stocked, 2]]);

        $this->exec([$draft->id])->assertSuccessful();

        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame(3.0, (float) $stocked->fresh()->stock_quantity);
    }

    public function test_same_article_and_quantity_but_other_employee_is_not_a_duplicate(): void
    {
        $a = $this->article('A', 1);
        $other = Employee::create(['name' => 'Otra persona', 'status' => true]);
        $this->activeDispatch('2026-10-03', $a, 1, $other);
        $draft = $this->draft('2026-10-02', [[$a, 1]]);

        $this->exec([$draft->id])->assertSuccessful();

        $this->assertSame(1, $draft->fresh()->details()->count(), 'otra persona: es otra salida real');
        $this->assertSame('active', $draft->fresh()->status);
    }

    public function test_a_draft_in_a_closed_month_is_skipped(): void
    {
        $a = $this->article('A', 5);
        $draft = $this->draft('2026-10-02', [[$a, 1]]);
        MonthlyClosure::withoutEvents(fn () => MonthlyClosure::create(['month' => 10, 'year' => 2026, 'is_closed' => true, 'closed_at' => now()]));

        $this->exec([$draft->id])->assertSuccessful();

        $this->assertSame('inactive', $draft->fresh()->status);
        $this->assertSame(5.0, (float) $a->fresh()->stock_quantity);
    }

    public function test_an_active_line_absorbs_only_one_duplicate(): void
    {
        $a = $this->article('A', 1);
        $this->activeDispatch('2026-10-03', $a, 1);
        $first = $this->draft('2026-10-01', [[$a, 1]]);
        $second = $this->draft('2026-10-02', [[$a, 1]]);

        $this->exec([$first->id, $second->id])->assertSuccessful();

        $this->assertSame(0, $first->fresh()->details()->count(), 'la primera es el duplicado');
        $this->assertSame(1, $second->fresh()->details()->count(), 'la segunda es otra salida real');
    }

    public function test_a_similar_line_with_enough_stock_is_a_real_repeated_exit_and_is_kept(): void
    {
        $a = $this->article('A', 5);
        $this->activeDispatch('2026-10-03', $a, 1);
        $draft = $this->draft('2026-10-02', [[$a, 1]]);

        $this->exec([$draft->id])->assertSuccessful();

        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame(1, $draft->fresh()->details()->count(), 'hay stock: la misma persona retiro lo mismo otra vez');
        $this->assertSame(3.0, (float) $a->fresh()->stock_quantity);
    }
}
