<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;

Route::get('/api/test-flash-products', function () {
    // 🎯 ប្រើប្រាស់ API Credentials ពិតប្រាកដរបស់បង Chetra
    $apiId       = 'RSMNGJ90S66GU8IC';
    $flashSecret = '1c5e38d93eadd3f18ff717f3d2d3a925e3549190ce373690c5e68917aa6e9497'; 
    
    $timestamp   = (string) time(); 
    $nonce       = bin2hex(random_bytes(16));
    
    // 👑 គន្លឹះពិត៖ នៅក្នុង API V2 របស់ Flash លីងទាញយក Services គឺត្រូវប្រើវិធីសាស្ត្រ POST 
    $method      = 'POST';
    $path        = '/api/reseller/v2/services'; 

    // 🎯 រៀបចំ Body ទិន្នន័យដើម្បីបោះទៅសួរយករបស់ហ្គេម Mobile Legends (Product ID: 3)
    $bodyData    = [
        'product_id' => 3
    ];
    
    ksort($bodyData);
    $jsonPayload = json_encode($bodyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $bodyHash    = hash('sha256', $jsonPayload); 

    // ផ្គុំចូលគ្នាជាខ្សែ Canonical ស្ដង់ដារសម្រាប់ធ្វើ Signature
    $canonical   = implode("\n", [$method, $path, $timestamp, $nonce, $bodyHash]);
    $signature   = hash_hmac('sha256', $canonical, $flashSecret);

    // 🚀 បាញ់ Request តាមទម្រង់ POST ទៅកាន់ប្រព័ន្ធ FlashTopUp Live
    $response = Http::withHeaders([
        'Content-Type'    => 'application/json',
        'X-FT-API-ID'     => $apiId,
        'X-FT-Timestamp'  => $timestamp,
        'X-FT-Nonce'      => $nonce,
        'X-FT-Signature'  => $signature,
    ])
    ->withoutVerifying()
    ->withBody($jsonPayload, 'application/json')
    ->post('https://api.flashtopup.com' . $path); 

    return response()->json($response->json());
});