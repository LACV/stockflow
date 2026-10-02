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
        Schema::create('monthly_closure_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monthly_closure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained('articles');
            $table->decimal('stock', 15, 4);
            $table->decimal('average_cost', 15, 4);
            $table->decimal('total_value', 15, 4);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_closure_balances');
    }
};
