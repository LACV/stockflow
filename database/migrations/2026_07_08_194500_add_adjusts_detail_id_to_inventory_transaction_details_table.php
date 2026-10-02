<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transaction_details', function (Blueprint $table) {
            $table->foreignId('adjusts_detail_id')
                ->nullable()
                ->after('observation')
                ->constrained('inventory_transaction_details')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transaction_details', function (Blueprint $table) {
            $table->dropForeign(['adjusts_detail_id']);
            $table->dropColumn('adjusts_detail_id');
        });
    }
};
