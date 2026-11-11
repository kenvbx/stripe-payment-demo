<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->string('refund_status')->nullable()->index();
            $table->timestamp('refunded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique(['stripe_refund_id']);
            $table->dropIndex(['refund_status']);
            $table->dropColumn(['stripe_refund_id', 'refund_status', 'refunded_at']);
        });
    }
};
