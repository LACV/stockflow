<?php

namespace App\Console\Commands;

use App\Models\MonthlyClosure;
use App\Services\MonthlyClosureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige 36 articulos del cierre de julio-2026 cuyo stock/PPP quedo
 * desalineado del Excel de referencia real "InventarioJuly2026.xlsx"
 * (fuente: comparacion linea a linea del 2026-09-03), y regenera agosto-2026
 * en cadena a partir del julio corregido.
 *
 * Incluye el articulo 357 (13948 FILTRO CONDENSADOR F83KB): la decision del
 * 2026-08-14 (AGENTS.md) fue sobre la CANTIDAD de apertura de junio (donde
 * el sistema, con razon, mantiene stock=1 vs la referencia que decia 2 -
 * eso NO se toca aqui). Al cierre de julio ambas fuentes coinciden en
 * cantidad restante=1; la diferencia de $100,240.36 es solo de VALORACION
 * de esa unidad ($2,989,682.79 en la referencia vs $2,889,442.43 calculado
 * por el sistema), por lo que se corrige aqui igual que a los otros 35, sin
 * contradecir la decision de cantidad ya documentada. Con estas 36
 * correcciones julio queda en 544,272,877.83 (el total original del Excel
 * de referencia).
 *
 * Requiere haber corrido antes `inventario:limpiar-articulo-prueba TEST99`
 * (o que no haya contaminacion de prueba activa).
 *
 * Solo toca el cierre HISTORICO (snapshots/balances de jul/ago). No toca el
 * inventario vivo (articles.stock_quantity/current_average_cost): si esos
 * 35 articulos tambien arrastran el mismo desfase en el stock actual, eso
 * requiere ubicar las transacciones reales faltantes, no forzar el numero.
 */
class CorregirCierreJulio2026 extends Command
{
    protected $signature = 'inventario:corregir-cierre-julio-2026
        {--dry-run : Muestra el plan sin escribir nada}';

    protected $description = 'Corrige 35 articulos de julio-2026 contra el Excel de referencia y regenera agosto en cadena. Deja julio en 544,172,637.47.';

    /**
     * article_id => [stock, average_cost, total_value] segun
     * InventarioJuly2026.xlsx (hoja "Movimientos July-2026"), columnas
     * CANTIDAD RESTANTE / PRECIO UNITARIO / VALOR TOTAL.
     */
    private const CORRECTIONS = [
        357 => [1, 2989682.79, 2989682.79],
        115 => [4, 15018.14, 60072.56],
        206 => [12, 155749.32, 1868991.84],
        573 => [188, 178296.01, 33519649.88],
        797 => [10, 348.21, 3482.10],
        1679 => [3, 27162.00, 81486.00],
        1683 => [25, 4637.32, 115933.00],
        1684 => [3, 4658.93, 13976.79],
        1685 => [41, 4233.99, 173593.59],
        1686 => [47, 4377.73, 205753.31],
        1687 => [21, 4310.05, 90511.05],
        1691 => [812, 1862.13, 1512049.56],
        1692 => [5, 21708.90, 108544.50],
        1698 => [2, 106535.35, 213070.70],
        1716 => [28, 104468.16, 2925108.48],
        1757 => [23, 8415.80, 193563.40],
        1758 => [16, 61629.21, 986067.36],
        1759 => [33, 10695.89, 352964.37],
        1760 => [10, 53699.74, 536997.40],
        1762 => [8, 3475.67, 27805.36],
        1763 => [6, 9368.29, 56209.74],
        1764 => [17, 22765.67, 387016.39],
        1824 => [12, 26809.75, 321717.00],
        1826 => [4, 1607.34, 6429.36],
        1839 => [2, 14816.67, 29633.34],
        1841 => [38, 6769.63, 257245.94],
        1842 => [6, 9288.14, 55728.84],
        1870 => [7, 10406.67, 72846.69],
        2017 => [1, 274000.00, 274000.00],
        2088 => [4, 18025.93, 72103.72],
        2128 => [1, 286003.85, 286003.85],
        2147 => [4, 23542.50, 94170.00],
        2151 => [154, 1254.35, 193169.90],
        2741 => [2, 29469.23, 58938.46],
        2761 => [1, 30666.67, 30666.67],
        2878 => [2, 166000.00, 332000.00],
    ];

    public function handle(): int
    {
        $julio = MonthlyClosure::where('month', 7)->where('year', 2026)->where('is_closed', true)->first();
        $agosto = MonthlyClosure::where('month', 8)->where('year', 2026)->where('is_closed', true)->first();

        if (! $julio) {
            $this->error('No se encontro el cierre cerrado de julio-2026.');

            return self::FAILURE;
        }

        $before = (float) DB::table('monthly_closure_snapshots')->where('monthly_closure_id', $julio->id)->sum('total_value');
        $this->info("Julio (closure {$julio->id}) total ANTES: ".number_format($before, 2));
        $this->info('Articulos a corregir: '.count(self::CORRECTIONS));

        $expectedAfter = $before;
        foreach (self::CORRECTIONS as $articleId => [$stock, $avg, $total]) {
            $current = DB::table('monthly_closure_snapshots')
                ->where('monthly_closure_id', $julio->id)
                ->where('article_id', $articleId)
                ->value('total_value') ?? 0.0;
            $expectedAfter += ($total - (float) $current);
        }
        $this->info('Total ESPERADO tras corregir: '.number_format($expectedAfter, 2).' (meta: 544,272,877.83)');

        if ($this->option('dry-run')) {
            foreach (self::CORRECTIONS as $articleId => [$stock, $avg, $total]) {
                $this->line("  articulo {$articleId}: stock={$stock} avg={$avg} total={$total}");
            }
            $this->info('Modo dry-run: no se escribio nada.');

            return self::SUCCESS;
        }

        if (! $this->confirm("Se corregiran {$this->countCorrections()} articulos en el cierre de julio y se regenerara agosto en cadena. ¿Continuar?")) {
            $this->warn('Operacion cancelada.');

            return self::SUCCESS;
        }

        $this->backup($julio, $agosto);

        DB::beginTransaction();

        try {
            $now = now()->toDateTimeString();

            foreach (self::CORRECTIONS as $articleId => [$stock, $avg, $total]) {
                DB::table('monthly_closure_snapshots')->updateOrInsert(
                    ['monthly_closure_id' => $julio->id, 'article_id' => $articleId],
                    ['stock_quantity' => $stock, 'average_cost' => $avg, 'total_value' => $total, 'updated_at' => $now, 'created_at' => $now]
                );

                DB::table('monthly_closure_balances')->updateOrInsert(
                    ['monthly_closure_id' => $julio->id, 'article_id' => $articleId],
                    ['stock' => $stock, 'average_cost' => $avg, 'total_value' => $total, 'updated_at' => $now, 'created_at' => $now]
                );
            }

            if ($agosto) {
                $service = app(MonthlyClosureService::class);
                $this->info('Regenerando agosto en cadena desde julio corregido...');
                $service->regenerateBalances($agosto->fresh());
                $service->regenerateSnapshot($agosto->fresh());
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('ROLLBACK: '.$e->getMessage());

            return self::FAILURE;
        }

        $afterJulio = (float) DB::table('monthly_closure_snapshots')->where('monthly_closure_id', $julio->id)->sum('total_value');
        $this->newLine();
        $this->info('Julio DESPUES: '.number_format($afterJulio, 2));

        if ($agosto) {
            $afterAgosto = (float) DB::table('monthly_closure_snapshots')->where('monthly_closure_id', $agosto->id)->sum('total_value');
            $this->info('Agosto DESPUES: '.number_format($afterAgosto, 2));
        }

        $this->info('Listo. Verifique /admin/preview-excel de julio y agosto, y descargue el Excel para validar.');

        return self::SUCCESS;
    }

    private function countCorrections(): int
    {
        return count(self::CORRECTIONS);
    }

    private function backup(MonthlyClosure $julio, ?MonthlyClosure $agosto): void
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $ids = array_filter([$julio->id, $agosto?->id]);
        $file = $dir.'/correccion_cierre_julio_2026_'.now()->format('Ymd_His').'.json';

        file_put_contents($file, json_encode([
            'generated_at' => now()->toDateTimeString(),
            'snapshots' => DB::table('monthly_closure_snapshots')->whereIn('monthly_closure_id', $ids)->get()->toArray(),
            'balances' => DB::table('monthly_closure_balances')->whereIn('monthly_closure_id', $ids)->get()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $this->info('Respaldo creado: '.$file);
    }
}
