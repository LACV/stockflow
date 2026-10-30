<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use App\Models\MonthlyClosure;
use App\Models\MonthlyClosureBalance;
use App\Models\Type;
use App\Services\MonthlyMovementsExcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class MonthlyMovementsExcelTest extends TestCase
{
    use RefreshDatabase;

    private function makeTransaction(string $type, string $date, ?string $invoice = null, ?string $po = null): InventoryTransaction
    {
        return InventoryTransaction::withoutEvents(fn () => InventoryTransaction::create([
            'type' => $type,
            'status' => 'active',
            'transaction_date' => $date,
            'invoice_number' => $invoice,
            'purchase_order' => $po,
        ]));
    }

    private function addDetail(InventoryTransaction $transaction, Article $article, float $qty, float $unitCost): void
    {
        InventoryTransactionDetail::withoutEvents(fn () => InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $article->id,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'total_cost' => $qty * $unitCost,
        ]));
    }

    private function sheetsOf(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);

        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = array_map(fn ($cell) => $cell->getValue(), $row->getCells());
            }
            $sheets[$sheet->getName()] = $rows;
        }

        $reader->close();

        return $sheets;
    }

    private function seedClosure(int $month, int $year, bool $closed, array $balances): MonthlyClosure
    {
        $closure = MonthlyClosure::withoutEvents(fn () => MonthlyClosure::create([
            'month' => $month,
            'year' => $year,
            'is_closed' => $closed,
            'closed_at' => $closed ? "{$year}-{$month}-28 23:59:59" : null,
        ]));

        foreach ($balances as $articleId => [$stock, $avg, $total]) {
            MonthlyClosureBalance::create([
                'monthly_closure_id' => $closure->id,
                'article_id' => $articleId,
                'stock' => $stock,
                'average_cost' => $avg,
                'total_value' => $total,
            ]);
        }

        return $closure;
    }

    private function countRows(array $tables): array
    {
        return array_combine($tables, array_map(fn ($t) => DB::table($t)->count(), $tables));
    }

    public function test_generates_monthly_movements_from_closed_balances_without_writes(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);

        $article = Article::create([
            'name' => 'Tuerca',
            'code' => 'A-100',
            'type_id' => $type->id,
            'reference' => 'M8',
            'stock_quantity' => 0,
            'current_average_cost' => 0,
            'is_active' => true,
        ]);

        $acero = Article::create([
            'name' => 'Acero Redondo',
            'code' => 'A-200',
            'type_id' => $type->id,
            'reference' => '3/4"',
            'stock_quantity' => 3,
            'current_average_cost' => 999.5,
            'is_active' => true,
        ]);

        $this->seedClosure(6, 2026, true, [
            $article->id => [5, 15018.54, 75092.70],
            $acero->id => [3, 999.50, 2998.50],
        ]);
        $this->seedClosure(7, 2026, true, [
            $article->id => [4, 15018.14, 60072.56],
            $acero->id => [3, 999.50, 2998.50],
        ]);

        $this->addDetail($this->makeTransaction('entry', '2026-06-10'), $article, 5, 12000);
        $this->addDetail($this->makeTransaction('entry', '2026-07-05', '10718', '82110'), $article, 2, 15000);
        $this->addDetail($this->makeTransaction('exit', '2026-07-06'), $article, 3, 15000);

        $tables = ['inventory_transactions', 'inventory_transaction_details', 'monthly_closures', 'monthly_closure_balances'];
        $before = $this->countRows($tables);

        $service = app(MonthlyMovementsExcelService::class);
        $path = tempnam(sys_get_temp_dir(), 'inv_test_').'.xlsx';
        $service->writeToFile($path, 7, 2026);

        $this->assertSame($before, $this->countRows($tables), 'El export NO debe escribir en la BD.');

        $sheets = $this->sheetsOf($path);

        $this->assertArrayHasKey('Entradas July-2026', $sheets);
        $this->assertArrayHasKey('Salida July-2026', $sheets);
        $this->assertArrayHasKey('Movimientos July-2026', $sheets);

        $entry = $sheets['Entradas July-2026'][1];
        $this->assertSame('A-100', $entry[0]);
        $this->assertSame('Tuerca - M8', $entry[1]);
        $this->assertEquals(2.0, $entry[2]);
        $this->assertEquals(15000.0, $entry[3]);
        $this->assertEquals(30000.0, $entry[4]);
        $this->assertSame('', $entry[5]);
        $this->assertSame('82110', $entry[6]);
        $this->assertSame('10718', $entry[7]);
        $this->assertSame('REPUESTOS', $entry[8]);

        $entryTotal = $sheets['Entradas July-2026'][2];
        $this->assertSame('Total Factura 10718', $entryTotal[6]);
        $this->assertEquals(30000.0, $entryTotal[7]);

        $entryTotales = $sheets['Entradas July-2026'][3];
        $this->assertSame('Total:', $entryTotales[6]);
        $this->assertEquals(30000.0, $entryTotales[7]);

        $exit = $sheets['Salida July-2026'][1];
        $this->assertSame('A-100', $exit[0]);
        $this->assertEquals(3.0, $exit[2]);
        $this->assertEqualsWithDelta(15013.2429, $exit[3], 0.001);
        $this->assertEqualsWithDelta(45039.7286, $exit[4], 0.001);

        $exitTotal = $sheets['Salida July-2026'][2];
        $this->assertSame('SubTotal Clase REPUESTOS', $exitTotal[6]);
        $this->assertEqualsWithDelta(45039.7286, $exitTotal[7], 0.001);

        $exitTotales = $sheets['Salida July-2026'][3];
        $this->assertSame('Total:', $exitTotales[6]);
        $this->assertEqualsWithDelta(45039.7286, $exitTotales[7], 0.001);

        $mov = $sheets['Movimientos July-2026'][1];
        $this->assertEquals(5.0, $mov[2]);
        $this->assertEqualsWithDelta(15018.54, $mov[3], 0.001);
        $this->assertEqualsWithDelta(75092.70, $mov[4], 0.001);
        $this->assertEquals(2.0, $mov[5]);
        $this->assertEquals(15000.0, $mov[6]);
        $this->assertEquals(30000.0, $mov[7]);
        $this->assertEquals(3.0, $mov[8]);
        $this->assertEqualsWithDelta(15013.2429, $mov[9], 0.001);
        $this->assertEqualsWithDelta(45039.7286, $mov[10], 0.001);
        $this->assertEquals(4.0, $mov[11]);
        $this->assertEqualsWithDelta(15018.14, $mov[12], 0.001);
        $this->assertEqualsWithDelta(60072.56, $mov[13], 0.001);
        $this->assertSame((string) $article->id, (string) $mov[14]);

        $movStatic = $sheets['Movimientos July-2026'][2];
        $this->assertSame('Acero Redondo - 3/4"', $movStatic[1]);
        $this->assertEquals(3.0, $movStatic[2]);
        $this->assertEqualsWithDelta(999.50, $movStatic[3], 0.001);
        $this->assertEqualsWithDelta(2998.50, $movStatic[4], 0.001);
        $this->assertEquals(0.0, $movStatic[5]);
        $this->assertEquals(0.0, $movStatic[8]);
        $this->assertEquals(0.0, $movStatic[9]);
        $this->assertEquals(3.0, $movStatic[11]);
        $this->assertEqualsWithDelta(999.50, $movStatic[12], 0.001);
        $this->assertEqualsWithDelta(2998.50, $movStatic[13], 0.001);
        $this->assertSame((string) $acero->id, (string) $movStatic[14]);

        $totales = $sheets['Movimientos July-2026'][3];
        $this->assertSame('TOTALES', $totales[1]);
        $this->assertEqualsWithDelta(78091.20, $totales[4], 0.001);
        $this->assertEqualsWithDelta(30000.0, $totales[7], 0.001);
        $this->assertEqualsWithDelta(45039.7286, $totales[10], 0.001);
        $this->assertEqualsWithDelta(63071.06, $totales[13], 0.001);
    }

    public function test_regression_last_detail_article_row_is_not_corrupted(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);

        $articleA = Article::create([
            'name' => 'Tuerca',
            'code' => 'A-100',
            'type_id' => $type->id,
            'reference' => 'M8',
            'stock_quantity' => 0,
            'current_average_cost' => 0,
            'is_active' => true,
        ]);

        $articleB = Article::create([
            'name' => 'Acero Redondo',
            'code' => 'A-200',
            'type_id' => $type->id,
            'reference' => '3/4"',
            'stock_quantity' => 0,
            'current_average_cost' => 0,
            'is_active' => true,
        ]);

        $this->seedClosure(6, 2026, true, [
            $articleA->id => [5, 1000.00, 5000.00],
            $articleB->id => [5, 2000.00, 10000.00],
        ]);
        $this->seedClosure(7, 2026, true, [
            $articleA->id => [6, 1100.00, 6600.00],
            $articleB->id => [4, 1900.00, 7600.00],
        ]);

        $this->addDetail($this->makeTransaction('entry', '2026-07-05'), $articleA, 2, 1100);
        $this->addDetail($this->makeTransaction('exit', '2026-07-06'), $articleB, 1, 2000);

        $service = app(MonthlyMovementsExcelService::class);
        $path = tempnam(sys_get_temp_dir(), 'inv_test_').'.xlsx';
        $service->writeToFile($path, 7, 2026);

        $sheets = $this->sheetsOf($path);
        $mov = $sheets['Movimientos July-2026'];

        $this->assertSame('A-100', $mov[1][0]);
        $this->assertSame((string) $articleA->id, (string) $mov[1][14]);
        $this->assertEquals(2.0, $mov[1][5]);
        $this->assertEqualsWithDelta(2200.0, $mov[1][7], 0.001);

        $this->assertSame('A-200', $mov[2][0]);
        $this->assertSame((string) $articleB->id, (string) $mov[2][14]);
        $this->assertEquals(0.0, $mov[2][5]);
        $this->assertEquals(0.0, $mov[2][7]);
        $this->assertEquals(1.0, $mov[2][8]);
        $this->assertEqualsWithDelta(2000.0, $mov[2][9], 0.001);
        $this->assertEqualsWithDelta(2000.0, $mov[2][10], 0.001);

        $totales = $mov[3];
        $this->assertSame('TOTALES', $totales[1]);
        $this->assertEqualsWithDelta(15000.0, $totales[4], 0.001);
        $this->assertEqualsWithDelta(2200.0, $totales[7], 0.001);
        $this->assertEqualsWithDelta(2000.0, $totales[10], 0.001);
        $this->assertEqualsWithDelta(14200.0, $totales[13], 0.001);
    }

    public function test_generates_excel_for_a_non_july_month(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);

        $article = Article::create([
            'name' => 'Tuerca',
            'code' => 'A-100',
            'type_id' => $type->id,
            'stock_quantity' => 0,
            'current_average_cost' => 0,
            'is_active' => true,
        ]);

        $this->seedClosure(5, 2026, true, [
            $article->id => [10, 1000.00, 10000.00],
        ]);
        $this->seedClosure(6, 2026, true, [
            $article->id => [12, 1500.00, 18000.00],
        ]);

        $this->addDetail($this->makeTransaction('entry', '2026-06-05', '999', 'P77'), $article, 2, 1500);
        $this->addDetail($this->makeTransaction('exit', '2026-06-06'), $article, 3, 1000);

        $service = app(MonthlyMovementsExcelService::class);
        $path = tempnam(sys_get_temp_dir(), 'inv_test_').'.xlsx';
        $this->assertSame('InventarioJune2026.xlsx', $service->filename(6, 2026));
        $service->writeToFile($path, 6, 2026);

        $sheets = $this->sheetsOf($path);

        $this->assertArrayHasKey('Entradas June-2026', $sheets);
        $this->assertArrayHasKey('Salida June-2026', $sheets);
        $this->assertArrayHasKey('Movimientos June-2026', $sheets);

        $mov = $sheets['Movimientos June-2026'][1];
        $this->assertEquals(10.0, $mov[2]);
        $this->assertEqualsWithDelta(1000.0, $mov[3], 0.001);
        $this->assertEqualsWithDelta(10000.0, $mov[4], 0.001);
        $this->assertEquals(2.0, $mov[5]);
        $this->assertEqualsWithDelta(1500.0, $mov[6], 0.001);
        $this->assertEqualsWithDelta(3000.0, $mov[7], 0.001);
        $this->assertEquals(3.0, $mov[8]);
        $this->assertEqualsWithDelta(1083.3333, $mov[9], 0.001);
        $this->assertEqualsWithDelta(3250.0, $mov[10], 0.001);
        $this->assertEquals(12.0, $mov[11]);
        $this->assertEqualsWithDelta(1500.0, $mov[12], 0.001);
        $this->assertEqualsWithDelta(18000.0, $mov[13], 0.001);

        $totales = $sheets['Movimientos June-2026'][2];
        $this->assertSame('TOTALES', $totales[1]);
        $this->assertEqualsWithDelta(10000.0, $totales[4], 0.001);
        $this->assertEqualsWithDelta(3000.0, $totales[7], 0.001);
        $this->assertEqualsWithDelta(3250.0, $totales[10], 0.001);
        $this->assertEqualsWithDelta(18000.0, $totales[13], 0.001);

        $entryTotal = $sheets['Entradas June-2026'][2];
        $this->assertSame('Total Factura 999', $entryTotal[6]);
        $this->assertEqualsWithDelta(3000.0, $entryTotal[7], 0.001);

        $entryTotales = $sheets['Entradas June-2026'][3];
        $this->assertSame('Total:', $entryTotales[6]);
        $this->assertEqualsWithDelta(3000.0, $entryTotales[7], 0.001);

        $exitTotal = $sheets['Salida June-2026'][2];
        $this->assertSame('SubTotal Clase REPUESTOS', $exitTotal[6]);
        $this->assertEqualsWithDelta(3250.0, $exitTotal[7], 0.001);

        $exitTotales = $sheets['Salida June-2026'][3];
        $this->assertSame('Total:', $exitTotales[6]);
        $this->assertEqualsWithDelta(3250.0, $exitTotales[7], 0.001);
    }

    public function test_first_month_uses_inicial_zero_when_no_previous_closure_exists(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);

        $article = Article::create([
            'name' => 'Tuerca',
            'code' => 'A-100',
            'type_id' => $type->id,
            'stock_quantity' => 0,
            'current_average_cost' => 0,
            'is_active' => true,
        ]);

        $this->seedClosure(7, 2024, true, [
            $article->id => [4, 1500.00, 6000.00],
        ]);

        $this->addDetail($this->makeTransaction('entry', '2024-07-10'), $article, 2, 1200);

        $service = app(MonthlyMovementsExcelService::class);
        $path = tempnam(sys_get_temp_dir(), 'inv_test_').'.xlsx';
        $this->assertSame('InventarioJuly2024.xlsx', $service->filename(7, 2024));
        $service->writeToFile($path, 7, 2024);

        $sheets = $this->sheetsOf($path);
        $mov = $sheets['Movimientos July-2024'][1];

        $this->assertEquals(0.0, $mov[2]);
        $this->assertEquals(0.0, $mov[3]);
        $this->assertEquals(0.0, $mov[4]);
        $this->assertEquals(2.0, $mov[5]);
        $this->assertEqualsWithDelta(1200.0, $mov[6], 0.001);
        $this->assertEquals(4.0, $mov[11]);
        $this->assertEqualsWithDelta(1500.0, $mov[12], 0.001);
        $this->assertEqualsWithDelta(6000.0, $mov[13], 0.001);

        $totales = $sheets['Movimientos July-2024'][2];
        $this->assertSame('TOTALES', $totales[1]);
        $this->assertEquals(0.0, $totales[4]);
        $this->assertEqualsWithDelta(2400.0, $totales[7], 0.001);
        $this->assertEqualsWithDelta(6000.0, $totales[13], 0.001);

        $entryTotales = $sheets['Entradas July-2024'][3];
        $this->assertSame('Total:', $entryTotales[6]);
        $this->assertEqualsWithDelta(2400.0, $entryTotales[7], 0.001);

        $this->assertCount(1, $sheets['Salida July-2024'], 'Sin salidas no debe haber fila de totales.');
    }

    public function test_falls_back_to_transaction_replay_when_selected_month_is_not_closed(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);

        $article = Article::create([
            'name' => 'Tuerca',
            'code' => 'A-100',
            'type_id' => $type->id,
            'stock_quantity' => 0,
            'current_average_cost' => 0,
            'is_active' => true,
        ]);

        $this->addDetail($this->makeTransaction('entry', '2026-07-05'), $article, 2, 15000);
        $this->addDetail($this->makeTransaction('exit', '2026-07-06'), $article, 3, 0);

        $service = app(MonthlyMovementsExcelService::class);
        $path = tempnam(sys_get_temp_dir(), 'inv_test_').'.xlsx';
        $service->writeToFile($path, 7, 2026);

        $sheets = $this->sheetsOf($path);
        $mov = $sheets['Movimientos July-2026'][1];

        $this->assertEquals(0.0, $mov[2]);
        $this->assertEquals(2.0, $mov[5]);
        $this->assertEquals(3.0, $mov[8]);
        $this->assertEquals(0.0, $mov[11]);
    }

    public function test_exit_without_stock_cover_is_valued_at_its_stored_unit_cost(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);
        $article = Article::create(['name' => 'TORNILLO', 'code' => 'T-1', 'type_id' => $type->id, 'stock_quantity' => 0, 'current_average_cost' => 0, 'is_active' => true]);

        $this->addDetail($this->makeTransaction('entry', '2026-10-02'), $article, 20, 1200);
        $this->addDetail($this->makeTransaction('exit', '2026-10-03'), $article, 20, 1200);
        $this->addDetail($this->makeTransaction('exit', '2026-10-27'), $article, 20, 1200);

        $salidas = collect(app(MonthlyMovementsExcelService::class)->previewArrays(10, 2026)['salidas'])
            ->filter(fn ($r) => ($r[0] ?? null) === 'T-1')
            ->values();

        $this->assertCount(2, $salidas);
        $this->assertEquals(24000.0, (float) $salidas[0][4]);
        $this->assertEquals(24000.0, (float) $salidas[1][4], 'Salida sin cobertura debe valer qty x costo guardado, no 0');
    }

    public function test_exit_with_stock_cover_still_uses_chain_ppp_not_stored_cost(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);
        $article = Article::create(['name' => 'TOALLA', 'code' => 'TO-1', 'type_id' => $type->id, 'stock_quantity' => 0, 'current_average_cost' => 0, 'is_active' => true]);

        $this->addDetail($this->makeTransaction('entry', '2026-10-02'), $article, 10, 100);
        $this->addDetail($this->makeTransaction('exit', '2026-10-03'), $article, 2, 999);

        $salida = collect(app(MonthlyMovementsExcelService::class)->previewArrays(10, 2026)['salidas'])
            ->first(fn ($r) => ($r[0] ?? null) === 'TO-1');

        $this->assertEquals(200.0, (float) $salida[4], 'Con cobertura se valora al PPP de la cadena');
    }

    public function test_closed_month_keeps_uncovered_exit_unchanged_because_it_was_already_delivered(): void
    {
        $type = Type::create(['name' => 'REPUESTOS']);
        $article = Article::create(['name' => 'TORNILLO', 'code' => 'T-2', 'type_id' => $type->id, 'stock_quantity' => 0, 'current_average_cost' => 0, 'is_active' => true]);

        $this->addDetail($this->makeTransaction('entry', '2026-10-02'), $article, 20, 1200);
        $this->addDetail($this->makeTransaction('exit', '2026-10-03'), $article, 20, 1200);
        $this->addDetail($this->makeTransaction('exit', '2026-10-27'), $article, 20, 1200);
        $this->seedClosure(10, 2026, true, []);

        $salidas = collect(app(MonthlyMovementsExcelService::class)->previewArrays(10, 2026)['salidas'])
            ->filter(fn ($r) => ($r[0] ?? null) === 'T-2')
            ->values();

        $this->assertEquals(24000.0, (float) $salidas[0][4]);
        $this->assertEquals(0.0, (float) $salidas[1][4], 'Mes cerrado ya entregado: no puede cambiar');
    }

    private function negativeAdjustmentScenario(): Article
    {
        $type = Type::create(['name' => 'REPUESTOS']);
        $article = Article::create(['name' => 'ACOPLE', 'code' => 'AC-1', 'type_id' => $type->id, 'stock_quantity' => 0, 'current_average_cost' => 0, 'is_active' => true]);

        $this->addDetail($this->makeTransaction('entry', '2026-10-02'), $article, 10, 100);
        $this->addDetail($this->makeTransaction('adjustment', '2026-10-05'), $article, -2, 100);

        return $article;
    }

    public function test_negative_adjustment_in_an_open_month_shows_as_a_positive_exit_without_touching_ppp(): void
    {
        $this->negativeAdjustmentScenario();

        $data = app(MonthlyMovementsExcelService::class)->previewArrays(10, 2026);

        $salida = collect($data['salidas'])->first(fn ($r) => ($r[0] ?? null) === 'AC-1');
        $this->assertEquals(2.0, (float) $salida[2]);
        $this->assertEquals(200.0, (float) $salida[4]);

        $mov = collect($data['movimientos'])->first(fn ($r) => ($r[0] ?? null) === 'AC-1');
        $this->assertEquals(2.0, (float) $mov[8], 'cantidad salida');
        $this->assertEquals(200.0, (float) $mov[10], 'valor salida');
        $this->assertEquals(8.0, (float) $mov[11], 'cantidad restante');
        $this->assertEquals(100.0, (float) $mov[12], 'PPP final no cambia');
        $this->assertEquals(800.0, (float) $mov[13], 'valor final');
        $this->assertEquals((float) $mov[4] + (float) $mov[7] - (float) $mov[10], (float) $mov[13], 'inicial + compras - salidas = final');
    }

    public function test_negative_adjustment_in_a_closed_month_keeps_its_original_output(): void
    {
        $this->negativeAdjustmentScenario();
        $this->seedClosure(10, 2026, true, []);

        $data = app(MonthlyMovementsExcelService::class)->previewArrays(10, 2026);

        $salida = collect($data['salidas'])->first(fn ($r) => ($r[0] ?? null) === 'AC-1');
        $this->assertEquals(-2.0, (float) $salida[2], 'mes cerrado ya entregado: no cambia');
        $this->assertEquals(-200.0, (float) $salida[4]);
    }
}
