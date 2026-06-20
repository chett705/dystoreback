<?php

namespace App\Http\Controllers;

use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use App\Services\TopupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TopupController extends Controller
{
    public function __construct(private readonly TopupService $topupService)
    {
    }

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

            // 🎯 បង្កើតចូល Database ភ្លាមៗជា pending (Admin Panel នឹងឃើញទិន្នន័យភ្លាមៗ លែងគាំង Cache)
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
            
            $transactionId = $paymentData['transaction_id'] ?? $order->order_no;

            $order->update([
                'gateway_transaction_id' => $transactionId,
                'gateway_checkout_url'   => $checkoutUrl,
                'gateway_hash'           => $paymentData['hash'] ?? null,
            ]);

            return response()->json([
                'message'      => 'KHQR generated successfully.',
                'order'        => $order,
                'checkout_url' => $checkoutUrl,
            ], 201);

        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Failed to generate payment QR Code.',
                'error'   => $exception->getMessage()
            ], 500);
        }
    }

    public function showOrder(TopupOrder $order): JsonResponse
    {
        $order->load(['game', 'package']);
        return response()->json(['data' => $order]);
    }

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

            if (str_starts_with($transactionId, '#')) {
                $transactionId = ltrim($transactionId, '#');
            }
            $cleanWebhookKey = trim($transactionId);

            // 🔍 ស្វែងរក Order នៅក្នុង Database តាមរយៈ transaction_id ឬ order_no
            $order = TopupOrder::where('gateway_transaction_id', $cleanWebhookKey)
                ->orWhere('gateway_transaction_id', '#' . $cleanWebhookKey)
                ->orWhere('order_no', $cleanWebhookKey)
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }

            if (in_array(strtolower($validated['status']), ['success', 'paid', 'completed'], true)) {
                
                // 🚀 កែប្រែស្ថានភាពទៅជា success
                $order->update([
                    'status'     => 'success',
                    'paid_at'    => now(),
                    'success_at' => now(),
                ]);

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