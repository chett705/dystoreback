<?php

use App\Http\Controllers\TopupController;
use Illuminate\Support\Facades\Route;

Route::prefix('topup')->group(function () {
    Route::get('/games', [TopupController::class, 'catalog'])->name('api.topup.games');
    Route::get('/games/{game}', [TopupController::class, 'showGame'])->name('api.topup.game');
    Route::post('/orders', [TopupController::class, 'createOrder'])->name('api.topup.orders.store');
    Route::get('/orders/{order}', [TopupController::class, 'showOrder'])->name('api.topup.orders.show');
    Route::post('/orders/{order}/checkout', [TopupController::class, 'generateCheckout'])->name('api.topup.orders.checkout');
    Route::post('/khqr/webhook', [TopupController::class, 'khqrWebhook'])->name('api.topup.khqr.webhook');
});
