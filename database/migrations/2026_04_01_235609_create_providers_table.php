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
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 150);
            $table->string('address', 80)->nullable();
            $table->string('email', 80)->nullable();
            $table->string('phone', 15)->nullable();
            $table->boolean('status')->default(true);
            $table->string('document_type', 45)->nullable();
            $table->string('identification', 20)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
