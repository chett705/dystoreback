<?php

namespace App\Http\Controllers;

use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topupService)
    {
    }

    /**
     * 📜 ទាញយកបញ្ជីហ្គេម និងកញ្ចប់តម្លៃដែលបើកដំណើរការ
     */
    public function catalog(): JsonResponse
    {
        $games = TopupGame::query()
            ->where('is_active', true)
            ->with(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $games,
        ]);
    }

    /**
     * 🎮 បង្ហាញព័ត៌មានលម្អិតនៃហ្គេមមួយ
     */
    public function showGame($idOrCode): JsonResponse
    {
        $game = TopupGame::query()
            ->where('id', $idOrCode)
            ->orWhere('code', $idOrCode)
            ->firstOrFail();

        $game->load(['packages' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')]);

        return response()->json([
            'data' => $game,
        ]);
    }

    /**
     * 🔍 មុខងារពិនិត្យមើលឈ្មោះអ្នកលេង (Player ID Lookup)
     */
    public function checkUsername(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_code' => ['required', 'string', 'exists:topup_games,code'],
            'player_id' => ['required', 'string', 'max:50'],
            'zone_id'   => ['nullable', 'string', 'max:50'], 
        ]);

        $zoneId = $validated['zone_id'] ?? '';

        $lookup = $this->topupService->lookupGameUsername(
            $validated['game_code'],
            $validated['player_id'],
            $zoneId
        );

        return response()->json([
            'message' => $lookup['success']
                ? 'Username lookup completed.'
                : ($lookup['message'] ?? 'Username lookup could not be completed.'),
            'result' => $lookup,
        ], $lookup['success'] ? 200 : 422);
    }

    /**
     * 🛒 មុខងារបង្កើតលីង QR (រក្សាទុកក្នុង Cache សិន មិនទាន់ចូល Database ទេ)
     */
    public function createOrder(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'game_code'       => ['required', 'string', 'exists:topup_games,code'],
                'package_id'      => ['required', 'integer'],
                'player_id'       => ['required', 'string', 'max:50'],
                'player_username' => ['nullable', 'string', 'max:191'],
                'zone_id'         => ['nullable', 'string', 'max:50'], 
                'payment_method'  => ['required', 'in:khqr'],
            ]);

            $game = TopupGame::query()->where('code', strtolower($validated['game_code']))->firstOrFail();
            $package = TopupPackage::query()->where('id', $validated['package_id'])->firstOrFail();

            // 🎯 បង្កើត Model Temporary មិនទាន់សរសេរចូល Database (Admin មិនទាន់ឃើញទេ)
            $tempOrder = new TopupOrder([
                'order_no'         => 'ORD_' . now()->format('YmdHis') . '_' . Str::upper(Str::random(8)),
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? null,
                'zone_id'          => $validated['zone_id'] ?? '', 
                'payment_method'   => $validated['payment_method'],
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'status'           => 'pending',
            ]);

            // 🚀 បាញ់សុំលីង QR បង់លុយពីធនាគារ
            [$checkoutUrl, $paymentData] = $this->topupService->buildKhqrCheckout($tempOrder);
            
            $transactionId = $paymentData['transaction_id'] ?? $tempOrder->order_no;

            // 🎯 ដំណោះស្រាយ៖ សម្អាតសញ្ញា # ចេញ និងប្ដូរទៅជា Lowercase មុនដាក់ចូល Cache
            if (str_starts_with($transactionId, '#')) {
                $transactionId = ltrim($transactionId, '#');
            }
            $cleanCacheKey = strtolower(trim($transactionId));

            // រក្សាទុកក្នុង Cache បណ្ដោះអាសន្នរយៈពេល ៣០ នាទី
            Cache::put('temp_order_' . $cleanCacheKey, [
                'order_no'         => $tempOrder->order_no,
                'topup_game_id'    => $game->id,
                'topup_package_id' => $package->id,
                'player_id'        => $validated['player_id'],
                'player_username'  => $validated['player_username'] ?? '',
                'zone_id'          => $validated['zone_id'] ?? '',
                'amount'           => $package->price,
                'diamond_amount'   => $package->diamond_amount,
                'hash'             => $paymentData['hash'] ?? null,
                'payload'          => $paymentData
            ], now()->addMinutes(30));

            return response()->json([
                'message'      => 'KHQR generated successfully. Waiting for payment.',
                'order'        => $tempOrder,
                'checkout_url' => $checkoutUrl,
            ], 201);

        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to generate payment QR Code.',
                'error'   => $exception->getMessage()
            ], 500);
        }
    }

    /**
     * 🔍 បង្ហាញព័ត៌មាន Order មួយ
     */
    public function showOrder(TopupOrder $order): JsonResponse
    {
        $order->load(['game', 'package']);
        return response()->json(['data' => $order]);
    }

    /**
     * ⚡ មុខងារតេស្ត Bypass បង្ខំឱ្យ Order ទៅជា Success (សម្រាប់ផ្ទាំង Admin Panel)
     */
    public function manualVerifyOrder(Request $request, $id): JsonResponse
    {
        $order = TopupOrder::findOrFail($id);
        
        if (in_array($order->status, ['pending', 'failed'])) {
            $order->status = 'success';
            $order->paid_at = now();
            $order->processing_at = now();
            $order->success_at = now();
            $order->save();

            try {
                $this->topupService->simulateSupplierFulfillment($order->load(['game', 'package']));
                $this->topupService->sendTelegramAlert($order, 'success');
            } catch (\Throwable $e) {
                Log::error("Supplier API Fulfillment error during bypass: " . $e->getMessage());
            }
        }

        $updatedOrder = $order->fresh(['game', 'package']);
        if ($updatedOrder) {
            $updatedOrder->status = 'success';
        }

        return response()->json([
            'message' => 'Order manual verification processed.',
            'order'   => $updatedOrder ?? $order
        ], 200);
    }

    /**
     * ❌ មុខងារលុប Order ចេញពី Database (សម្រាប់ប៊ូតុងលុបពណ៌ក្រហមលើ Admin)
     */
    public function destroyOrder($id): JsonResponse
    {
        try {
            $order = TopupOrder::findOrFail($id);
            $order->delete();
            return response()->json(['success' => true, 'message' => 'Order deleted successfully.'], 200);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to delete order.'], 500);
        }
    }

    /**
     * 🔔 ប្រព័ន្ធស្ទាក់ចាប់ការបាញ់លុយពីធនាគារ (KHQR Webhook)
     * ➔ បង់លុយរួចរាល់ពិតប្រាកដ ទើបបង្កើតចូល DB ទៅជា "success" និងលោតបង្ហាញក្នុង Admin ភ្លាមៗ
     */
    public function khqrWebhook(Request $request): JsonResponse
    {
        Log::info('🎯 WEBHOOK HIT FROM BANK:', $request->all());

        try {
            $validated = $request->validate([
                'transaction_id' => ['required', 'string'],
                'status'         => ['required', 'string'],
                'amount'         => ['nullable', 'numeric'],
            ]);

            $transactionId = $validated['transaction_id'];

            // 🎯 ដំណោះស្រាយ៖ ចម្រោះកាត់សញ្ញា # ចេញ និងប្ដូរទៅជា Lowercase ឱ្យស៊ីគ្នាជាមួយ Cache Key 
            if (str_starts_with($transactionId, '#')) {
                $transactionId = ltrim($transactionId, '#');
            }
            $cleanWebhookKey = strtolower(trim($transactionId));

            // ទាញយកទិន្នន័យបណ្ដោះអាសន្នចេញពី Cache
            $cached = Cache::get('temp_order_' . $cleanWebhookKey);

            if (!$cached) {
                Log::error("❌ Webhook Error: Cache Key expired or mismatch: temp_order_" . $cleanWebhookKey);
                return response()->json(['message' => 'Order payload expired or not found'], 404);
            }

            // ប្រសិនបើធនាគារបញ្ជាក់ថាបង់លុយរួចរាល់ពិតប្រាកដ (Pay Ready)
            if (in_array(strtolower($validated['status']), ['success', 'paid', 'completed'], true)) {
                
                // 🎯 ដំណោះស្រាយ៖ ប្រើ updateOrCreate ជំនួស create ការពារការបាក់ដួល Unique Key ពេល Webhook បាញ់មកជាន់គ្នា
                $order = TopupOrder::updateOrCreate(
                    [
                        'order_no' => $cached['order_no']
                    ],
                    [
                        'topup_game_id'          => $cached['topup_game_id'],
                        'topup_package_id'       => $cached['topup_package_id'],
                        'player_id'              => $cached['player_id'],
                        'player_username'        => $cached['player_username'] ?? '',
                        'zone_id'                => $cached['zone_id'] ?? '',
                        'payment_method'         => 'khqr',
                        'amount'                 => $cached['amount'],
                        'diamond_amount'         => $cached['diamond_amount'],
                        'status'                 => 'success', // 🚀 ចូល Database ជា success ភ្លាមៗ
                        'gateway_transaction_id' => $validated['transaction_id'],
                        'gateway_hash'           => $cached['hash'] ?? null,
                        'gateway_payload'        => $cached['payload'] ?? null,
                        'paid_at'                => now(),
                        'success_at'             => now(),
                    ]
                );

                // សម្អាត Cache ចោលកុំឱ្យស្ទះ Memory
                Cache::forget('temp_order_' . $cleanWebhookKey);
                Log::info("✅ Webhook processed successfully. Order Stored ID: " . $order->id);

                // បាញ់បញ្ជូន Diamonds ទៅកាន់ Server ហ្គេមរបស់អតិថិជន
                try {
                    $supplierResult = $this->topupService->simulateSupplierFulfillment($order->load(['game', 'package']));

                    if (isset($supplierResult['success']) && $supplierResult['success']) {
                        $order->update([
                            'supplier_order_id' => $supplierResult['supplier_order_id'] ?? null,
                            'supplier_payload'  => $supplierResult,
                        ]);
                        $this->topupService->sendTelegramAlert($order, 'success');
                    } else {
                        $order->update([
                            'status'           => 'failed',
                            'failed_at'        => now(),
                            'failure_reason'   => $supplierResult['message'] ?? 'Supplier API error.',
                            'supplier_payload' => $supplierResult,
                        ]);
                        $this->topupService->sendTelegramAlert($order, 'failed');
                    }
                } catch (\Throwable $supplierEx) {
                    Log::error("⚠️ Supplier API fulfillment failed: " . $supplierEx->getMessage());
                }

                return response()->json(['success' => true, 'message' => 'Payment Success & Data Stored.', 'order' => $order], 200);
            }

            return response()->json(['message' => 'Payment status is non-success.'], 400);

        } catch (\Throwable $criticalException) {
            Log::error("🚨 CRITICAL WEBHOOK EXCEPTION: " . $criticalException->getMessage(), [
                'trace' => $criticalException->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Critical Server Error inside Webhook logic.',
                'error'   => $criticalException->getMessage()
            ], 500);
        }
    }
}