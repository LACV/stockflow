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
        Schema::table('article_prices', function (Blueprint $table) {
            $table->string('source')->nullable()->after('date');
            $table->unsignedBigInteger('transaction_id')->nullable()->after('source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('article_prices', function (Blueprint $table) {
            $table->dropColumn(['source', 'transaction_id']);
        });
    }
};
