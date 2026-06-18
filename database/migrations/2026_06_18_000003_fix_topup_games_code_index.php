<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE topup_games MODIFY code VARCHAR(191) NOT NULL');

        DB::statement('CREATE UNIQUE INDEX topup_games_code_unique ON topup_games (code)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX topup_games_code_unique ON topup_games');
        DB::statement('ALTER TABLE topup_games MODIFY code VARCHAR(255) NOT NULL');
    }
};
