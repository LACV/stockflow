<?php

namespace App\Console\Commands;

use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixStockNegativo extends Command
{
    protected $signature = 'inventario:corregir-negativos';

    protected $description = 'Lleva a 0 el stock de los artículos con stock negativo y deja el PPP en positivo. Con respaldo previo.';

    public function handle(): int
    {
        $afectados = Article::where('stock_quantity', '<', 0)->get();
        $pppNegativos = Article::where('current_average_cost', '<', 0)->get();

        if ($afectados->isEmpty() && $pppNegativos->isEmpty()) {
            $this->info('No hay artículos con stock o PPP negativo. Nada que corregir.');

            return self::SUCCESS;
        }

        $totalNegativo = $afectados->sum(fn ($a) => (float) $a->stock_quantity);

        $this->warn('Se corregirán '.$afectados->count().' artículos con stock negativo.');
        $this->line('   - Unidades totales a corregir (abs): '.number_format(abs($totalNegativo), 4, '.', ','));
        $this->line('   - Artículos con PPP negativo: '.$pppNegativos->count());

        if (! $this->confirm('Crear respaldo y aplicar la corrección? (stock -> 0, PPP negativo -> positivo)')) {
            $this->warn('Operación cancelada.');

            return self::SUCCESS;
        }

        $todos = $afectados->merge($pppNegativos)->unique('id');

        $backup = [
            'generated_at' => now()->toDateTimeString(),
            'command' => 'inventario:corregir-negativos',
            'summary' => [
                'total_articulos' => $todos->count(),
                'unidades_negativas' => round($totalNegativo, 4),
            ],
            'articles' => $todos->map(fn ($a) => [
                'id' => $a->id,
                'code' => $a->code,
                'name' => $a->name,
                'stock_quantity' => (float) $a->stock_quantity,
                'current_average_cost' => (float) $a->current_average_cost,
            ])->values()->all(),
        ];

        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/correccion_stock_negativo_'.now()->format('Ymd_His').'.json';
        file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        $this->info('Respaldo creado: '.$file);

        DB::transaction(function () use ($pppNegativos) {
            Article::where('stock_quantity', '<', 0)->update(['stock_quantity' => 0]);

            foreach ($pppNegativos as $a) {
                Article::where('id', $a->id)->update([
                    'current_average_cost' => abs((float) $a->current_average_cost),
                ]);
            }
        });

        $this->info('Corrección aplicada.');

        $restantes = Article::where('stock_quantity', '<', 0)->count();
        $pppRest = Article::where('current_average_cost', '<', 0)->count();

        $this->line('Verificación: artículos con stock negativo restantes: '.$restantes);
        $this->line('Verificación: artículos con PPP negativo restantes: '.$pppRest);

        if ($restantes === 0) {
            $this->info('OK: ningún artículo quedó con stock negativo.');
        } else {
            $this->error("Atención: aún quedan {$restantes} artículos con stock negativo.");
        }

        return self::SUCCESS;
    }
}
