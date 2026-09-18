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
        Schema::create('bank_requests', function (Blueprint $table) {
            $table->id();
            $table->string('bank')->index();
            $table->foreignId('bank_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->text('url');
            $table->json('request_headers')->nullable();
            $table->text('request_body')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_requests');
    }
};
