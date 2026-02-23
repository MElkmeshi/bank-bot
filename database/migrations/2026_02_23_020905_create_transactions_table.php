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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_session_id')->constrained()->cascadeOnDelete();
            $table->string('account_number');
            $table->string('reference');
            $table->string('code');
            $table->string('code_description');
            $table->string('type'); // credit / debit
            $table->string('type_label');
            $table->string('amount');
            $table->string('amount_formatted');
            $table->string('currency');
            $table->string('event'); // INIT / REVR
            $table->text('description')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_account_number')->nullable();
            $table->timestamp('transaction_date');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['bank_session_id', 'account_number', 'reference', 'type', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
