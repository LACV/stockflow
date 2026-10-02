<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionDetail;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    public function index()
    {
        $products = Article::all();

        return view('inventario.index', compact('products'));
    }

    public function entrada(Request $request)
    {
        $product = Article::findOrFail($request->product_id);

        $product->stock_quantity += $request->quantity;
        $product->save();

        $transaction = InventoryTransaction::create([
            'type' => 'entry',
            'transaction_date' => now()->toDateString(),
            'status' => 'inactive',
        ]);

        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $product->id,
            'quantity' => $request->quantity,
        ]);

        return back()->with('success', 'Entrada registrada. Stock actual: '.$product->stock_quantity);
    }

    public function salida(Request $request)
    {
        $product = Article::findOrFail($request->product_id);

        if ($product->stock_quantity < $request->quantity) {
            return back()->with('error', 'Stock insuficiente. Disponible: '.$product->stock_quantity);
        }

        $product->stock_quantity -= $request->quantity;
        $product->save();

        $transaction = InventoryTransaction::create([
            'type' => 'exit',
            'transaction_date' => now()->toDateString(),
            'status' => 'inactive',
        ]);

        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $product->id,
            'quantity' => $request->quantity,
        ]);

        return back()->with('success', 'Salida registrada. Stock actual: '.$product->stock_quantity);
    }

    public function ajuste(Request $request)
    {
        $product = Article::findOrFail($request->product_id);

        $ajuste = $request->quantity;

        $product->stock_quantity += $ajuste;
        $product->save();

        $transaction = InventoryTransaction::create([
            'type' => 'adjustment',
            'transaction_date' => now()->toDateString(),
            'status' => 'inactive',
        ]);

        InventoryTransactionDetail::create([
            'inventory_transaction_id' => $transaction->id,
            'article_id' => $product->id,
            'quantity' => $ajuste,
        ]);

        return back()->with('success', 'Ajuste aplicado. Stock actual: '.$product->stock_quantity);
    }
}
