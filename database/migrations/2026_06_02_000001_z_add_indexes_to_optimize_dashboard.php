<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->index('is_active');
            $table->index(['is_active', 'stock_quantity']);
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->index('status');
            $table->index('transaction_date');
            $table->index(['status', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'stock_quantity']);
            $table->dropIndex(['is_active']);
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex(['status', 'transaction_date']);
            $table->dropIndex(['transaction_date']);
            $table->dropIndex(['status']);
        });
    }
};
