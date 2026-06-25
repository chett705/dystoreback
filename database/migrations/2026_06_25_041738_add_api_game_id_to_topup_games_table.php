<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('topup_games', 'api_game_id')) {
            return;
        }

        Schema::table('topup_games', function (Blueprint $table) {
            // 🎯 ថែមប្រអប់ api_game_id សម្រាប់ចាំស្តាប់ ID លេខ 3 ឬ 5 របស់ FlashTopUp
            $table->unsignedBigInteger('api_game_id')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('topup_games', function (Blueprint $table) {
            $table->dropColumn('api_game_id');
        });
    }
};