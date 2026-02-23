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
        Schema::create('bank_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_chat_id');
            $table->string('bank');
            $table->string('customer_id');
            $table->string('device_id');
            $table->string('access_token')->nullable();
            $table->string('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->string('verification_reference')->nullable();
            $table->timestamps();

            $table->unique(['telegram_chat_id', 'bank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_sessions');
    }
};
