<?php

use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('welcome');
});

// Webhook ផ្លូវការ
Route::post('/api/khqr-webhook', [TopupController::class, 'khqrWebhook']);

// ⚡ ផ្លូវពិសេសសម្រាប់សម្អាត Cache (កែសម្រួលឱ្យស្រាលជាងមុន ការពារការគាំង 502)
Route::get('/api/clear-route', function () {
    try {
        Artisan::call('optimize:clear'); // ហៅតែមួយខ្សែនេះ គឺវាសម្អាតគ្រប់ cache ទាំងអស់តែម្តង
        return response()->json([
            'success' => true,
            'message' => 'System caches cleared successfully!'
        ], 200);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});