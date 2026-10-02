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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 80)->nullable();
            $table->string('phone', 15)->nullable();
            $table->boolean('status')->default(true);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('identification_e', 30)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
