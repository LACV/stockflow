<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // inventory_transactions ya tiene status string, solo necesitamos asegurar que 'draft' sea válido
        // Articles no tiene status, lo agregamos
        Schema::table('articles', function (Blueprint $table) {
            $table->string('status')->default('active')->after('id');
        });

        // Monthly closures tampoco tiene status de borrador
        Schema::table('monthly_closures', function (Blueprint $table) {
            $table->string('draft_note')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('monthly_closures', function (Blueprint $table) {
            $table->dropColumn('draft_note');
        });
    }
};
