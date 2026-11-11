<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->char('checkout_request_hash', 64)->nullable();
            $table->json('checkout_parameters')->nullable();
            $table->string('checkout_state')->nullable();
            $table->uuid('checkout_claim_token')->nullable();
            $table->timestamp('checkout_claim_expires_at')->nullable();
            $table->timestamp('checkout_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn([
            'checkout_request_hash', 'checkout_parameters', 'checkout_state',
            'checkout_claim_token', 'checkout_claim_expires_at', 'checkout_expires_at',
        ]));
    }
};
