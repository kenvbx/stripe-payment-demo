<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description');
            $table->unsignedInteger('price_cents');
            $table->char('currency', 3)->default('usd');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('products'); }
};
