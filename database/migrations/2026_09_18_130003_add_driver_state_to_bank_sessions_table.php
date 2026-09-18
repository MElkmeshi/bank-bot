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
            $table->text('access_token')->nullable()->change();
            $table->text('refresh_token')->nullable()->change();
            $table->text('credentials')->nullable()->after('default_account_number');
            $table->json('meta')->nullable()->after('credentials');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_sessions', function (Blueprint $table) {
            $table->string('access_token')->nullable()->change();
            $table->string('refresh_token')->nullable()->change();
            $table->dropColumn(['credentials', 'meta']);
        });
    }
};
