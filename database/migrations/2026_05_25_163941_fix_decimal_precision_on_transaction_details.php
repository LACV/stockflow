<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fix precision: de decimal(15,2) a decimal(15,4) para coincidir con articles.stock_quantity
        Schema::table('inventory_transaction_details', function (Blueprint $table) {
            $table->decimal('quantity', 15, 4)->change();
            $table->decimal('unit_cost', 15, 4)->change();
            $table->decimal('total_cost', 15, 4)->change();
            $table->decimal('resulting_average_cost', 15, 4)->default(0)->change();
        });

        Schema::table('article_prices', function (Blueprint $table) {
            $table->decimal('quantity', 15, 4)->default(0)->change();
            $table->decimal('price', 15, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transaction_details', function (Blueprint $table) {
            $table->decimal('quantity', 15, 2)->change();
            $table->decimal('unit_cost', 15, 2)->change();
            $table->decimal('total_cost', 15, 2)->change();
            $table->decimal('resulting_average_cost', 15, 2)->default(0)->change();
        });

        Schema::table('article_prices', function (Blueprint $table) {
            $table->decimal('quantity', 15, 2)->default(0)->change();
            $table->decimal('price', 15, 2)->default(0)->change();
        });
    }
};
