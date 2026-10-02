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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->nullable();
            $table->string('name');
            $table->foreignId('brand_id')->nullable()->constrained('brands');
            $table->foreignId('department_id')->nullable()->constrained('departments');
            $table->foreignId('type_id')->nullable()->constrained('types');
            $table->decimal('stock_quantity', 15, 4)->default(0);
            $table->decimal('current_average_cost', 15, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
