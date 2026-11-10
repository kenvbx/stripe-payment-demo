<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('payments', function (Blueprint $table): void {
        $table->id(); $table->string('stripe_payment_intent_id')->nullable()->unique(); $table->string('stripe_event_id')->nullable()->unique();
        $table->string('customer_name'); $table->string('customer_email'); $table->string('product_name'); $table->unsignedInteger('amount'); $table->char('currency',3)->default('usd');
        $table->string('status')->default('created'); $table->string('webhook_status')->default('pending'); $table->string('idempotency_key')->unique(); $table->text('last_error')->nullable(); $table->timestamp('paid_at')->nullable(); $table->timestamps(); $table->index(['status','created_at']); $table->index('webhook_status');
    }); }
    public function down(): void { Schema::dropIfExists('payments'); }
};
