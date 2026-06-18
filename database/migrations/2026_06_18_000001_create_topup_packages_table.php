<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('topup_packages')) {
            return;
        }

        Schema::create('topup_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topup_game_id')->constrained('topup_games')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('diamond_amount');
            $table->decimal('price', 12, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_packages');
    }
};
