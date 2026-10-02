<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_closures', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable()->after('closed_at');
            $table->foreignId('reopened_by')->nullable()->constrained('users')->after('reopened_at');
        });
    }

    public function down(): void
    {
        Schema::table('monthly_closures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn('reopened_at');
        });
    }
};
