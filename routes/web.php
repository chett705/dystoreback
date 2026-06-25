<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'message' => 'DyzzStore API is running.',
        'hint' => 'Use the /api/topup and /api/admin endpoints from your React frontend.',
    ]);
});
Route::get('/update-game-ids', function () {
    try {
        // 🎯 ១. សម្រាប់ Mobile Legends ធម្មតា
        DB::table('topup_games')->where('code', 'mlbb')->update(['api_game_id' => 3]);

        // 🎯 ២. សម្រាប់ Mobile Legends Exclusive
        DB::table('topup_games')->where('code', 'mlbb_exclusive')->update(['api_game_id' => 5]);

        // 🎯 ៣. សម្រាប់ Magic Chess Gogo (បន្ថែមថ្មីផ្អែកលើរូបភាពនេះ)
        DB::table('topup_games')
            ->where('code', 'magic_chest_gogo') // ⚠️ ឆែកមើលអក្សរ code ក្នុង DB បងមើល ក្រែងលោបងសរសេរ 'magic_chess_gogo' (អក្សរ s)
            ->update(['api_game_id' => 107]);

        return "🎉 Successfully updated Game IDs on Aiven! (MLBB = 3, Exclusive = 5, Magic Chess Gogo = 107)";
    } catch (\Exception $e) {
        return "⚠️ Error: " . $e->getMessage();
    }
});

use Illuminate\Support\Facades\Http;

Route::get('/api/test-flash-products', function () {
    $apiId       = 'RSMNGJ90S66GU8IC';
    $flashSecret = trim(env('FLASH_TOPUP_SECRET_KEY'));
    $timestamp   = (string) time(); 
    $nonce       = bin2hex(random_bytes(16));
    $path        = '/api/reseller/v2/services'; // លីងសម្រាប់ទាញយកផលិតផលទាំងអស់

    $canonical = implode("\n", ['GET', $path, $timestamp, $nonce, hash('sha256', '')]);
    $signature = hash_hmac('sha256', $canonical, $flashSecret);

    $response = Http::withHeaders([
        'X-FT-API-ID'    => $apiId,
        'X-FT-Timestamp' => $timestamp,
        'X-FT-Nonce'     => $nonce,
        'X-FT-Signature' => $signature,
    ])->withoutVerifying()->get('https://api.flashtopup.com' . $path);

    return response()->json($response->json());
});