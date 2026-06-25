<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TopupGame;
use App\Models\TopupOrder;
use App\Models\TopupPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        // 🎯 ១. គណនាចំណូលតែពី Orders ណាដែលជោគជ័យប៉ុណ្ណោះ
        $revenue = TopupOrder::query()
            ->where('status', 'success')
            ->sum('amount');

        return response()->json([
            'success' => true,
            'stats' => [
                'games' => TopupGame::count(),
                'packages' => TopupPackage::count(),
                'orders' => TopupOrder::where('status', 'success')->count(), // 🎯 រាប់តែពួកបង់លុយរួច
                'revenue' => '$' . number_format($revenue, 2),
                'orders_pending' => TopupOrder::query()->where('status', 'pending')->count(),
                'orders_paid' => TopupOrder::query()->where('status', 'paid')->count(),
                'orders_success' => TopupOrder::query()->where('status', 'success')->count(),
            ],
            'games' => TopupGame::query()
                ->with(['packages' => fn ($query) => $query->orderBy('sort_order')])
                ->orderBy('name')
                ->get(),
            'packages' => TopupPackage::query()
                ->with('game')
                ->orderBy('topup_game_id')
                ->orderBy('sort_order')
                ->get(),
            
            // 🎯 ២. ទាញយកបញ្ជី Orders បង្ហាញលើ Admin៖ បង្ខំយកតែពួកបង់លុយរួចរាល់ (Success) ប៉ុណ្ណោះ
            // ពួក Pending (មិនទាន់បង់លុយ) គឺលែងបង្ហាញរំខានភ្នែកលើ Admin ទៀតហើយ
            'orders' => TopupOrder::query()
                ->with(['game', 'package'])
                ->where('status', 'success') 
                ->latest()
                ->limit(100)
                ->get(),
        ], 200);
    }

    public function storeGame(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:191', 'unique:topup_games,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $game = TopupGame::query()->create([
            'code' => strtolower(trim($validated['code'])),
            'name' => trim($validated['name']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json(['message' => 'Game created successfully.', 'data' => $game], 201);
    }

    public function updateGame(Request $request, $id): JsonResponse
    {
        $game = TopupGame::query()->findOrFail($id);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:191', 'unique:topup_games,code,' . $game->id],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $game->update([
            'code' => strtolower(trim($validated['code'])),
            'name' => trim($validated['name']),
            'is_active' => $request->has('is_active') ? $request->boolean('is_active') : $game->is_active,
        ]);

        return response()->json(['message' => 'Game updated successfully.', 'data' => $game]);
    }

    /**
     * 🎯 កែសម្រួល៖ គាំទ្រការរក្សាទុក SKU ពេលបង្កើតកញ្ចប់ថ្មីពីផ្ទាំង Admin
     */
    public function storePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'game_id'        => ['required', 'integer'], 
            'name'           => ['nullable', 'string', 'max:255'], 
            'price'          => ['required', 'numeric', 'min:0'],
            'diamond_amount' => ['required', 'integer', 'min:1'], 
            'sku'            => ['nullable', 'string', 'max:191'], // 🎯 បន្ថែមការអនុញ្ញាតឱ្យបញ្ជូន SKU មកពី Admin
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $packageName = $request->filled('name') ? trim($validated['name']) : $validated['diamond_amount'] . ' Diamonds';

        $package = TopupPackage::query()->create([
            'game_id'        => $validated['game_id'], 
            'topup_game_id'  => $validated['game_id'], 
            'name'           => $packageName,
            'price'          => $validated['price'],
            'diamond_amount' => $validated['diamond_amount'], 
            'sku'            => $validated['sku'] ? trim($validated['sku']) : null, // 🎯 រក្សាទុកទិន្នន័យ SKU
            'sort_order'     => $validated['sort_order'] ?? 0,
            'is_active'      => $request->boolean('is_active', true),
        ]);

        return response()->json(['message' => 'Package created successfully.', 'package' => $package->fresh(['game'])], 201);
    }

    /**
     * 🎯 កែសម្រួល៖ គាំទ្រការកែប្រែ (Update) លេខ SKU នៃកញ្ចប់ចាស់ៗពីផ្ទាំង Admin
     */
    public function updatePackage(Request $request, TopupPackage $package): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['nullable', 'string', 'max:255'],
            'price'          => ['required', 'numeric', 'min:0'],
            'diamond_amount' => ['required', 'integer', 'min:1'], 
            'sku'            => ['nullable', 'string', 'max:191'], // 🎯 បន្ថែមការអនុញ្ញាតឱ្យកែប្រែ SKU
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $packageName = $request->filled('name') ? trim($validated['name']) : $validated['diamond_amount'] . ' Diamonds';

        $package->update([
            'name'           => $packageName,
            'price'          => $validated['price'],
            'diamond_amount' => $validated['diamond_amount'], 
            'sku'            => $validated['sku'] ? trim($validated['sku']) : null, // 🎯 Update ទិន្នន័យ SKU ទៅ Aiven
            'sort_order'     => $validated['sort_order'] ?? $package->sort_order,
            'is_active'      => $request->boolean('is_active'),
        ]);

        return response()->json(['message' => 'Package updated successfully.', 'package' => $package->fresh(['game'])]);
    }

    public function updateOrder(Request $request, TopupOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'status'          => ['required', 'in:pending,paid,processing,success,failed'],
            'player_username' => ['nullable', 'string', 'max:191'], 
        ]);

        $updateData = ['status' => $validated['status']];
        if ($request->has('player_username') && !is_null($request->input('player_username'))) {
            $updateData['player_username'] = trim($validated['player_username']);
        }

        $order->update($updateData);

        return response()->json(['message' => 'Order updated successfully.', 'order' => $order->fresh(['game', 'package'])]);
    }

    public function manualVerifyOrder(Request $request, $id): JsonResponse
    {
        $order = TopupOrder::findOrFail($id);
        
        if (in_array($order->status, ['pending', 'failed'])) {
            $order->status = 'success';
            $order->paid_at = now();
            $order->success_at = now();
            $order->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Order bypassed successfully.',
            'order'   => $order->fresh(['game', 'package'])
        ], 200);
    }

    public function destroyOrder($id): JsonResponse
    {
        $order = TopupOrder::findOrFail($id);
        $order->delete();

        return response()->json(['success' => true, 'message' => 'Order deleted successfully.'], 200);
    }

    public function destroyGame($id): JsonResponse
    {
        try {
            $game = TopupGame::query()->find($id);

            if (!$game) {
                return response()->json([
                    'success' => false,
                    'message' => 'Game not found.'
                ], 404);
            }

            $game->delete();

            return response()->json([
                'success' => true,
                'message' => 'Game deleted successfully.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete game: ' . $e->getMessage()
            ], 500);
        }
    }
}