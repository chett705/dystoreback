<?php

use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// 🔔 Webhook Endpoint ផ្លូវការសម្រាប់ទទួលទិន្នន័យពី KHQR និង FlashTopUp Callback
Route::post('/api/khqr-webhook', [TopupController::class, 'khqrWebhook']);

// ⚡ ផ្លូវពិសេសសម្រាប់សម្អាត Cache ងាយស្រួលហៅពីលើ Render
Route::get('/api/clear-route', function () {
    Artisan::call('route:clear');
    Artisan::call('config:clear');
    Artisan::call('cache:clear');
    
    return response()->json([
        'success' => true,
        'message' => 'All system caches (Route, Config, Cache) have been cleared successfully!'
    ], 200);
});