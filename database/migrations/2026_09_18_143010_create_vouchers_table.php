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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_session_id')->constrained()->cascadeOnDelete();
            $table->string('account_number');
            $table->string('provider_name');
            $table->string('amount');
            $table->string('currency');
            $table->text('code');
            $table->string('serial')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('purchased_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
