<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('currency', 10);
            $table->string('address', 255);
            $table->decimal('balance', 36, 18)->default(0);
            $table->decimal('locked_balance', 36, 18)->default(0);
            $table->enum('status', ['active', 'frozen', 'closed'])->default('active');
            $table->timestamps();

            $table->index(['user_id', 'currency']);
            $table->index('status');
            $table->unique(['user_id', 'currency', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
