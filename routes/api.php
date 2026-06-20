<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 🛒 1. Public Routes សម្រាប់អតិថិជន (Topup Shop)
|--------------------------------------------------------------------------
*/
Route::prefix('topup')->group(function () {
    Route::get('/games', [TopupController::class, 'catalog'])->name('api.topup.games');
    Route::get('/games/{game}', [TopupController::class, 'showGame'])->name('api.topup.game');
    Route::post('/check-username', [TopupController::class, 'checkUsername'])->name('api.topup.check-username');
    Route::post('/orders', [TopupController::class, 'createOrder'])->name('api.topup.orders.store');
    Route::get('/orders/{order}', [TopupController::class, 'showOrder'])->name('api.topup.orders.show');
    Route::get('/orders/{order}/checkout', [TopupController::class, 'generateCheckout'])->name('api.topup.orders.checkout');
});

/*
|--------------------------------------------------------------------------
| 🔔 2. Public Route សម្រាប់ធនាគារបាញ់លុយចូល (KHQR Webhook)
|--------------------------------------------------------------------------
| 🔗 URL ពេញ៖ https://dystoreback.onrender.com/api/khqr/webhook
| ចំណាំ៖ ត្រូវតែដាក់នៅខាងក្រៅ មិនឱ្យជាប់ Middleware ទើបធនាគារបាញ់ចូល Database កើត
*/
Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook'])->name('api.topup.khqr.webhook');

/*
|--------------------------------------------------------------------------
| 🛡️ 3. Protected Routes សម្រាប់ផ្ទាំង Admin Panel
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    // 🔐 Route សម្រាប់ Login Admin (មិនទាន់ត្រូវការ Token)
    Route::post('/login', [AdminAuthController::class, 'login'])->name('api.admin.login');

    // 🛡️ ក្រុមកូដការពារដោយ Middleware (Admin Token)
    Route::middleware('admin.token')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('api.admin.logout');

        // 📊 Dashboard Overview (ទាញយកទិន្នន័យ Orders មកបង្ហាញលើ React Admin)
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('api.admin.dashboard');

        // 🎮 គ្រប់គ្រង Games
        Route::post('/games', [DashboardController::class, 'storeGame'])->name('api.admin.games.store');
        Route::patch('/games/{game}', [DashboardController::class, 'updateGame'])->name('api.admin.games.update');

        // 📦 គ្រប់គ្រង Packages
        Route::post('/packages', [DashboardController::class, 'storePackage'])->name('api.admin.packages.store');
        Route::patch('/packages/{package}', [DashboardController::class, 'updatePackage'])->name('api.admin.packages.update');

        // 🔄 គ្រប់គ្រង Orders (កែប្រែស្ថានភាព Status និង Player Username)
        Route::patch('/orders/{order}', [DashboardController::class, 'updateOrder'])->name('api.admin.orders.update');

        // ⚡ មុខងារចុចបង្ខំឱ្យជោគជ័យ (Bypass Success) - 🎯 លុបពាក្យ /admin ចេញកុំឱ្យជាន់គ្នា
        Route::post('/orders/{id}/manual-verify', [TopupController::class, 'manualVerifyOrder'])->name('api.admin.orders.manual-verify');

        // ❌ មុខងារចុចលុប Order ចេញពី Database - 🎯 លុបពាក្យ /admin ចេញដូចគ្នា
        Route::delete('/orders/{id}', [TopupController::class, 'destroyOrder'])->name('api.admin.orders.destroy');
    });
});