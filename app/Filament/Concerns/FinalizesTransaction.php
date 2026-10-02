<?php

namespace App\Filament\Concerns;

use App\Models\MonthlyClosure;
use App\Services\MovementService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

trait FinalizesTransaction
{
    public function finalize(): void
    {
        $transaction = $this->record;

        if ($transaction->status !== 'inactive') {
            return;
        }

        $date = Carbon::parse($transaction->transaction_date);
        $closure = MonthlyClosure::where('month', $date->month)
            ->where('year', $date->year)
            ->first();

        $isReopened = $closure && $closure->isReopened() && auth()->user()?->can('Reopen:MonthlyClosure');
        $monthClosed = $closure && $closure->is_closed && ! $isReopened;

        if ($monthClosed) {
            $monthName = $date->locale('es')->isoFormat('MMMM');
            Notification::make()
                ->title('Mes cerrado')
                ->body("No se puede guardar porque {$monthName} {$date->year} ya está cerrado.")
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $details = $transaction->details()->get();

        if ($details->isEmpty()) {
            Notification::make()
                ->title('Sin artículos')
                ->body('Agregue al menos un artículo antes de guardar la factura.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        $type = strtoupper($transaction->type);
        $movement = app(MovementService::class);

        if (! $isReopened && in_array($type, ['EXIT', 'ADJUSTMENT'], true)) {
            $insuficientes = $movement->validarStockSuficiente($details, $type);

            if (! empty($insuficientes)) {
                Notification::make()
                    ->title('Stock insuficiente')
                    ->body("No se guardó la factura. Estos artículos no tienen stock suficiente:\n".implode("\n", $insuficientes))
                    ->danger()
                    ->persistent()
                    ->send();

                return;
            }
        }

        DB::transaction(function () use ($transaction, $details, $movement, $isReopened) {
            foreach ($details as $detail) {
                if ($isReopened) {
                    [$newStock, $newAvgCost] = $movement->applyMovement(
                        $transaction->type,
                        0,
                        0,
                        (float) $detail->quantity,
                        (float) $detail->unit_cost,
                    );

                    if ($transaction->type === 'EXIT' || ($transaction->type === 'ADJUSTMENT' && (float) $detail->quantity < 0)) {
                        $detail->unit_cost = 0;
                    }

                    if (! $detail->total_cost || $detail->total_cost == 0) {
                        $detail->total_cost = abs((float) $detail->quantity) * (float) ($detail->unit_cost ?: 0);
                    }

                    $detail->resulting_average_cost = $newAvgCost;
                    $detail->saveQuietly();
                } else {
                    $article = $detail->article;
                    $currentStock = (float) $article->stock_quantity;
                    $currentAvgCost = (float) $article->current_average_cost;

                    [$newStock, $newAvgCost, $detailUpdates] = $movement->applyMovement(
                        $transaction->type,
                        $currentStock,
                        $currentAvgCost,
                        (float) $detail->quantity,
                        (float) $detail->unit_cost,
                    );

                    if (! empty($detailUpdates)) {
                        foreach ($detailUpdates as $key => $value) {
                            $detail->{$key} = $value;
                        }
                    }

                    if (! $detail->total_cost || $detail->total_cost == 0) {
                        $detail->total_cost = abs((float) $detail->quantity) * (float) $detail->unit_cost;
                    }

                    $detail->resulting_average_cost = $newAvgCost;
                    $detail->saveQuietly();

                    $article->stock_quantity = $newStock;
                    $article->current_average_cost = $newAvgCost;
                    $article->saveQuietly();

                    $movement->createPriceRecord(
                        $article,
                        $detail,
                        $transaction->type,
                        $transaction->transaction_date,
                    );

                    $movement->notifyIfStockCritical($article, $newStock);
                }
            }

            $transaction->status = 'active';
            $transaction->saveQuietly();
        });

        $this->record->refresh();

        try {
            Notification::make()
                ->title('Registro guardado')
                ->body('El registro fue guardado exitosamente. Ya no es posible editarlo.')
                ->success()
                ->persistent()
                ->send();

            if (method_exists($this, 'refreshFormData')) {
                $this->refreshFormData(['status']);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $this->redirect($this->getResource()::getUrl('index'));
    }
}
