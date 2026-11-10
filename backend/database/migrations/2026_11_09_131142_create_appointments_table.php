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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->dateTimeTz('starts_at');
            $table->dateTimeTz('ends_at');
            $table->string('status')->default('pending')->index();
            $table->string('timezone')->default('Asia/Ho_Chi_Minh');
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['staff_profile_id', 'starts_at', 'ends_at', 'status'], 'appointments_staff_time_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
