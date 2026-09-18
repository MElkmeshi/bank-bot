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
        Schema::table('bank_requests', function (Blueprint $table) {
            $table->text('request_headers')->nullable()->change();
            $table->text('response_headers')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bank_requests', function (Blueprint $table) {
            $table->json('request_headers')->nullable()->change();
            $table->json('response_headers')->nullable()->change();
        });
    }
};
