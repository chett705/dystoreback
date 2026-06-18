<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // 🎯 ស្វែងរកគណនីអ្នកប្រើប្រាស់តាមរយៈ Email
        $user = User::query()
            ->where('email', $validated['email'])
            ->first();

        // ផ្ទៀងផ្ទាត់គណនី និងលេខសម្ងាត់
        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 422);
        }

        // បង្កើត Random Token ថ្មី
        $token = Str::random(64);

        // រក្សាទុក SHA-256 Hash នៃ Token ចូលទៅក្នុង Database ដើម្បីសុវត្ថិភាព
        $user->forceFill([
            'admin_api_token_hash' => hash('sha256', $token),
        ])->save();

        return response()->json([
            'message' => 'Login successful.',
            'token_type' => 'Bearer',
            'token' => $token,        // 🚀 ថែម Key នេះ ដើម្បីការពារបើ React ដេញរក res.token
            'access_token' => $token, // 🚀 រក្សាទុក Key នេះ បើ React ដេញរក res.access_token
            'admin' => $user->only(['id', 'name', 'email']), 
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $user->forceFill([
                'admin_api_token_hash' => null,
            ])->save();
        }

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }
}