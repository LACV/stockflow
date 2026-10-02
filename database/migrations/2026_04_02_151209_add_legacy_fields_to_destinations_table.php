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
        Schema::table('destinations', function (Blueprint $table) {
            $table->date('installation_date')->nullable();
            $table->text('detailed')->nullable();
            $table->string('capacity')->nullable();
            $table->string('power')->nullable();
            $table->string('physical_dimensions')->nullable();
            $table->text('fundamental_pieces')->nullable();
            $table->text('supplies')->nullable();
            $table->text('repair_details')->nullable();
            $table->string('frequency_use')->nullable();
            $table->string('image')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropColumn([
                'installation_date',
                'detailed',
                'capacity',
                'power',
                'physical_dimensions',
                'fundamental_pieces',
                'supplies',
                'repair_details',
                'frequency_use',
                'image',
            ]);
        });
    }
};
