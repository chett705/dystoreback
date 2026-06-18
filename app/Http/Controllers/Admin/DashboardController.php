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
    /**
     * 📊 មុខងារទាញយកទិន្នន័យសរុបសម្រាប់ផ្ទាំង Dashboard Overview & Management
     */
    public function index(): JsonResponse
    {
        // គណនាប្រាក់ចំណូលសរុប (Revenue) ពី Orders ណាដែលមានស្ថានភាព 'success'
        $revenue = TopupOrder::query()
            ->where('status', 'success')
            ->sum('amount');

        return response()->json([
            'stats' => [
                'games' => TopupGame::count(),
                'packages' => TopupPackage::count(),
                'orders' => TopupOrder::count(),
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
            'orders' => TopupOrder::query()
                ->with(['game', 'package'])
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    /**
     * 🎮 មុខងារបង្កើតហ្គេមថ្មី (Create New Game)
     */
    public function storeGame(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:191', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/', 'unique:topup_games,code'],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $game = TopupGame::query()->create([
            'code' => strtolower(trim($validated['code'])),
            'name' => trim($validated['name']),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Game created successfully.',
            'data' => $game,
        ], 201);
    }

    /**
     * 📦 មុខងារបង្កើតកញ្ចប់តម្លៃ Diamonds ថ្មី (Fixed Column & Name Safe)
     */
    public function storePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 🎯 កែសម្រួលដក 'exists' ចេញបណ្ដោះអាសន្នដើម្បីកុំឱ្យទាស់ ID ពេលរត់ local ឬ production
            'game_id'        => ['required', 'integer'], 
            'name'           => ['nullable', 'string', 'max:255'], // 🎯 ដូរទៅជា nullable (មិនទារដាច់ខាត)
            'price'          => ['required', 'numeric', 'min:0'],
            'diamond_amount' => ['required', 'integer', 'min:1'], // 🎯 បន្ថែមការឆែកគ្រាប់ Diamonds
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        // 🎯 បង្កើតឈ្មោះ Auto-generate បើក្នុង React Form អត់មានបញ្ជូន Name មក
        $packageName = $request->filled('name') 
            ? trim($validated['name']) 
            : $validated['diamond_amount'] . ' Diamonds';

        $package = TopupPackage::query()->create([
            'game_id'        => $validated['game_id'],       // 🎯 បោះទៅទាំងពីរការពារខុសឈ្មោះ Column ក្នុង DB
            'topup_game_id'  => $validated['game_id'], 
            'name'           => $packageName,
            'price'          => $validated['price'],
            'diamond_amount' => $validated['diamond_amount'], // 🎯 បញ្ចូលគ្រាប់ Diamonds ទៅ Database
            'sort_order'     => $validated['sort_order'] ?? 0,
            'is_active'      => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'message' => 'Package created successfully.',
            'package' => $package->fresh(['game']), 
        ], 201);
    }

    /**
     * ✏️ មុខងារកែប្រែ/បច្ចុប្បន្នភាពកញ្ចប់តម្លៃ (Fixed Column & Name Safe)
     */
    public function updatePackage(Request $request, TopupPackage $package): JsonResponse
    {
        $validated = $request->validate([
            'name'           => ['nullable', 'string', 'max:255'],
            'price'          => ['required', 'numeric', 'min:0'],
            'diamond_amount' => ['required', 'integer', 'min:1'], // 🎯 បន្ថែមការឆែកគ្រាប់ Diamonds
            'sort_order'     => ['nullable', 'integer', 'min:0'],
            'is_active'      => ['nullable', 'boolean'],
        ]);

        $packageName = $request->filled('name') 
            ? trim($validated['name']) 
            : $validated['diamond_amount'] . ' Diamonds';

        $package->update([
            'name'           => $packageName,
            'price'          => $validated['price'],
            'diamond_amount' => $validated['diamond_amount'], // 🎯 កែប្រែគ្រាប់ Diamonds ទៅ Database
            'sort_order'     => $validated['sort_order'] ?? $package->sort_order,
            'is_active'      => $request->boolean('is_active'),
        ]);

        return response()->json([
            'message' => 'Package updated successfully.',
            'package' => $package->fresh(['game']), 
        ]);
    }

    /**
     * 🔄 មុខងារកែប្រែស្ថានភាព Order (Fixed Nullable Username)
     */
    public function updateOrder(Request $request, TopupOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'status'          => ['required', 'in:pending,paid,processing,success,failed'],
            'player_username' => ['nullable', 'string', 'max:191'], // 🎯 ទៅជា nullable
        ]);

        $updateData = [
            'status' => $validated['status'],
        ];

        // 🎯 ឆែកមើលបើមានវាយ username ពិតប្រាកដទើបព្រមយកទៅ Update ការពារ SQL Constraint Error
        if ($request->has('player_username') && !is_null($request->input('player_username'))) {
            $updateData['player_username'] = trim($validated['player_username']);
        }

        $order->update($updateData);

        return response()->json([
            'message' => 'Order updated successfully.',
            'data'    => $order->fresh(['game', 'package']),
        ]);
    }
}