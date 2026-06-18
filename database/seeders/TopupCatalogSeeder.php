<?php

namespace Database\Seeders;

use App\Models\TopupGame;
use App\Models\TopupPackage;
use Illuminate\Database\Seeder;

class TopupCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            [
                'code' => 'mlbb',
                'name' => 'Mobile Legends',
                'packages' => [
                    ['name' => '5 Diamonds', 'diamond_amount' => 5, 'price' => 0.50, 'sort_order' => 1],
                    ['name' => '10 Diamonds', 'diamond_amount' => 10, 'price' => 0.90, 'sort_order' => 2],
                    ['name' => '50 Diamonds', 'diamond_amount' => 50, 'price' => 4.50, 'sort_order' => 3],
                    ['name' => '100 Diamonds', 'diamond_amount' => 100, 'price' => 8.90, 'sort_order' => 4],
                    ['name' => '250 Diamonds', 'diamond_amount' => 250, 'price' => 21.50, 'sort_order' => 5],
                ],
            ],
        ];

        foreach ($catalog as $gameData) {
            $game = TopupGame::query()->updateOrCreate(
                ['code' => $gameData['code']],
                ['name' => $gameData['name'], 'is_active' => true]
            );

            foreach ($gameData['packages'] as $packageData) {
                TopupPackage::query()->updateOrCreate(
                    [
                        'topup_game_id' => $game->id,
                        'name' => $packageData['name'],
                    ],
                    [
                        'diamond_amount' => $packageData['diamond_amount'],
                        'price' => $packageData['price'],
                        'sort_order' => $packageData['sort_order'],
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
