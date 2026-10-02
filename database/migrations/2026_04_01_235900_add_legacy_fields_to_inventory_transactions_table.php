<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->foreignId('provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number')->nullable();
            $table->string('purchase_order')->nullable();
            $table->string('status')->default('active');
            $table->integer('month')->nullable();
            $table->integer('year')->nullable();
        });

        Schema::table('inventory_transaction_details', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('destination_id')->nullable()->constrained()->nullOnDelete();
            $table->date('movement_date')->nullable();
            $table->time('movement_time')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_transaction_details', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['destination_id']);
            $table->dropColumn(['employee_id', 'destination_id', 'movement_date', 'movement_time']);
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropForeign(['provider_id']);
            $table->dropColumn(['provider_id', 'invoice_number', 'purchase_order', 'status', 'month', 'year']);
        });
    }
};
