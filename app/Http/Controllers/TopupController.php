<?php

namespace App\Http\Controllers;

use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse; // 🎯 ធានាថាមាន Import មួយនេះដើម្បីកុំឱ្យលោត Error JsonResponse
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topupService)
    {
    }

    /**
     * បង្ហាញបញ្ជីហ្គេមទាំងអស់ដែលកំពុងបើកដំណើរការ
     */
    public function catalog(): JsonResponse
    {
        $games = TopupGame::query()
            ->where('is_active', true)
            ->with(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $games]);
    }

    /**
     * បង្ហាញព័ត៌មានហ្គេមលម្អិតតាមរយៈ ID ឬ Code
     */
    public function showGame($idOrCode): JsonResponse
    {
        $game = TopupGame::query()->where('id', $idOrCode)->orWhere('code', $idOrCode)->firstOrFail();
        $game->load(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);
        return response()->json(['data' => $game]);
    }

    /**
     * 🎯 មុខងារ Check ID (រូបមន្តផ្លូវការចុះបន្ទាត់ \n របស់ក្រុមហ៊ុន FlashTopUp)
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $gameCode = $request->input('game_code') ?? $request->input('validation_code');
        $playerId = $request->input('player_id') ?? $request->input('user_id');
        $zoneId   = $request->input('zone_id') ?? $request->input('server_id') ?? '';

        // ចាប់យកម៉ោង និង Nonce ពី Client (Postman/Frontend) បើមាន ដើម្បីងាយស្រួលផ្ទៀងផ្ទាត់
        $timestamp = (string) ($request->header('X-FT-Timestamp') ?? $request->input('ft_timestamp') ?? time());
        $nonce     = $request->header('X-FT-Nonce') ?? $request->input('ft_nonce') ?? bin2hex(random_bytes(16));

        if (!$gameCode || !$playerId) {
            return response()->json(['message' => 'game_code and player_id are required.'], 422);
        }

        try {
            $apiId     = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
            $secretKey = trim(env('FLASH_TOPUP_SECRET_KEY')); 

            $path = '/api/reseller/v2/check-id'; 
            $method = 'POST';

            // រៀបចំកញ្ចប់ Body តាមលំដាប់តួអក្សរ A-Z
            $bodyData = [
                'server_id'       => trim($zoneId),
                'user_id'         => trim($playerId),
                'validation_code' => strtolower(trim($gameCode)),
            ];
            ksort($bodyData);

            // បម្លែងជា JSON String គ្រាប់ស្ងួតគ្មាន Space (ដូច JSON.stringify)
            $rawJsonBody = json_encode($bodyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            // 🎯 ផលិត Canonical String តាមរូបមន្តគំរូក្រុមហ៊ុន (ដោតសញ្ញាចុះបន្ទាត់ \n)
            $bodyHash = hash('sha256', $rawJsonBody);
            $canonical = implode("\n", [$method, $path, $timestamp, $nonce, $bodyHash]);
            $signature = hash_hmac('sha256', $canonical, $secretKey);

            // 🚀 បាញ់ត្រង់ទៅ FlashTopUp
            $response = Http::withHeaders([
                'Content-Type'    => 'application/json',
                'X-FT-API-ID'     => $apiId,
                'X-FT-Timestamp'  => $timestamp,
                'X-FT-Nonce'      => $nonce,
                'X-FT-Signature'  => $signature,
            ])
            ->withoutVerifying() 
            ->withBody($rawJsonBody, 'application/json')
            ->post('https://api.flashtopup.com' . $path);

            if ($response->successful()) {
                $apiData = $response->json();
                
                $playerName = $apiData['account_name'] 
                              ?? $apiData['data']['account_name'] 
                              ?? $apiData['player_name'] 
                              ?? null;

                return response()->json([
                    'message' => 'Done',
                    'result' => [
                        'player_name' => $playerName,
                        'username'    => $playerName, 
                        'name'        => $playerName, 
                        'raw_data'    => $apiData     
                    ]
                ]);
            }

            // 🎯 កែសម្រួលដុំ Catch Error នេះឱ្យមានសុវត្ថិភាពខ្ពស់បំផុត ទោះ API បោះទិន្នន័យទម្រង់ណាក៏មិនគាំងដែរ
            $errorData = $response->json();
            $statusCode = $response->status();
            
            return response()->json([
                'message' => $errorData['message'] ?? $errorData['error']['message'] ?? 'API Rejected', 
                'error'   => $errorData
            ], $statusCode >= 100 && $statusCode < 600 ? $statusCode : 400);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * បង្កើត Order ថ្មីក្នុងប្រព័ន្ធ និងទាញយក KHQR
     */
    public function createOrder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'game_code'       => ['required', 'string'],
                'package_id'      => ['required', 'integer'],
                'player_id'       => ['required', 'string'],
                'player_username' => ['nullable', 'string'],
                'zone_id'         => ['nullable', 'string'],
                'payment_method'  => ['required'],
            ]);

            $game = TopupGame::where('code', strtolower($validated['game_code']))->firstOrFail();
            $package = TopupPackage::findOrFail($validated['package_id']);

            $order = TopupOrder::create([
                'order_no'         => 'ORD_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(8)),
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? '',
                'zone_id'          => $validated['zone_id'] ?? '',
                'payment_method'   => $validated['payment_method'],
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'status'           => 'pending',
            ]);

            [$checkoutUrl, $paymentData] = $this->topupService->buildKhqrCheckout($order);
            
            $order->update([
                'gateway_transaction_id' => $paymentData['transaction_id'] ?? $order->order_no,
                'gateway_checkout_url'   => $checkoutUrl,
                'gateway_hash'           => $paymentData['hash'] ?? null,
            ]);

            return response()->json(['message' => 'QR Generated', 'order' => $order, 'checkout_url' => $checkoutUrl], 201);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Error', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * បង្ហាញព័ត៌មានលម្អិតនៃ Order
     */
    public function showOrder(TopupOrder $order): JsonResponse
    {
        return response()->json(['data' => $order->load(['game', 'package'])]);
    }

    /**
     * 🎯 មុខងារទទួល Webhook រួម (KHQR Payment ➡️ ដំណើរការបាញ់ពេជ្រអូតូ)
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('🎯 WEBHOOK HIT FROM BANK OR FLASH TOPUP:', $request->all());

        try {
            if ($request->has('event') || $request->has('reference_id') || $request->has('order_status')) {
                $referenceId = $request->input('reference_id');
                $orderStatus = $request->input('order_status');

                $order = TopupOrder::where('order_no', $referenceId)->first();
                if (!$order) return response()->json(['message' => 'Order not found'], 404);

                if (strtolower($orderStatus) === 'completed') {
                    $order->update(['status' => 'success', 'success_at' => now()]);
                    return response()->json(['success' => true, 'message' => 'Fulfillment Completed']);
                }
                if (in_array(strtolower($orderStatus), ['failed', 'refunded', 'canceled'])) {
                    $order->update(['status' => 'failed']);
                    return response()->json(['success' => false, 'message' => 'Order failed']);
                }
                return response()->json(['message' => 'Status handled']);
            }

            // ផ្នែក Bank Webhook -> ដំណើរការបាញ់ពេជ្រទៅ FlashTopUp អូតូ
            if (!$request->has('transaction_id') || !$request->has('status')) {
                return response()->json(['message' => 'Invalid Webhook'], 400);
            }

            $transactionId = $request->input('transaction_id');
            $cleanWebhookKey = trim(str_replace('#', '', $transactionId));

            $order = TopupOrder::where('gateway_transaction_id', $cleanWebhookKey)
                ->orWhere('order_no', $cleanWebhookKey)
                ->first();

            if (!$order) return response()->json(['message' => 'Order not found'], 404);

            if (in_array(strtolower($request->input('status')), ['success', 'paid', 'completed'])) {
                if (in_array($order->status, ['processing', 'success'])) {
                    return response()->json(['success' => true, 'message' => 'Already processed']);
                }

                $order->update(['status' => 'processing', 'paid_at' => now()]);

                // 🚀 លំហូរបាញ់ការកុម្ម៉ង់ទិញទៅ FlashTopUp តាមរូបមន្តចុះបន្ទាត់ \n ផ្លូវការ
                try {
                    $order->load(['game', 'package']);
                    $serviceCode = $order->package->sku ?? $order->package->code; 

                    $apiId       = trim(env('FLASH_TOPUP_API_ID', 'RSMNGJ90S66GU8IC'));
                    $flashSecret = trim(env('FLASH_TOPUP_SECRET_KEY'));
                    $timestamp   = (string) time(); 
                    $nonce       = bin2hex(random_bytes(16));
                    
                    $path        = '/api/reseller/v2/order'; 
                    $method      = 'POST';

                    $orderBody = [
                        'quantity'     => 1,
                        'reference_id' => $order->order_no, 
                        'server_id'    => $order->zone_id,
                        'service_code' => $serviceCode,
                        'user_id'      => $order->player_id,
                    ];
                    ksort($orderBody);
                    $orderJson = json_encode($orderBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    
                    // 🎯 គណនា Signature តាមរូបមន្តចុះបន្ទាត់ \n ផ្លូវការរបស់ក្រុមហ៊ុន
                    $orderBodyHash = hash('sha256', $orderJson);
                    $orderCanonical = implode("\n", [$method, $path, $timestamp, $nonce, $orderBodyHash]);
                    $orderSignature = hash_hmac('sha256', $orderCanonical, $flashSecret);

                    $flashResponse = Http::withHeaders([
                        'Content-Type'    => 'application/json',
                        'X-FT-API-ID'     => $apiId,
                        'X-FT-Timestamp'  => $timestamp,
                        'X-FT-Nonce'      => $nonce,
                        'X-FT-Signature'  => $orderSignature,
                    ])
                    ->withoutVerifying() 
                    ->withBody($orderJson, 'application/json')
                    ->post('https://api.flashtopup.com' . $path);

                    if ($flashResponse->successful()) {
                        Log::info("🚀 Fulfillment Success Initiated: {$order->order_no}");
                    } else {
                        Log::error("❌ Fulfillment API Refused: {$order->order_no}", $flashResponse->json());
                        $order->update(['status' => 'manual_hold']);
                    }

                } catch (\Throwable $ex) {
                    Log::critical("🚨 Error calling Flash Topup API: " . $ex->getMessage());
                    $order->update(['status' => 'manual_hold']);
                }

                return response()->json(['success' => true, 'message' => 'Payment recorded, Flash Topup API triggered']);
            }
            
            return response()->json(['message' => 'Non-success status'], 400);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}