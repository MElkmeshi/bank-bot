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
        Schema::table('bank_sessions', function (Blueprint $table) {
            $table->string('default_account_number')->nullable()->after('verification_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_sessions', function (Blueprint $table) {
            $table->dropColumn('default_account_number');
        });
    }
};
