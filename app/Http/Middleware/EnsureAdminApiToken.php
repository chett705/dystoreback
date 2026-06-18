<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // 🚀 ដំណោះស្រាយ៖ ចាប់យក Token ពី Bearer ផង បើគ្មានទេ ឱ្យវាទៅចាប់ពី Header 'X-Admin-Token' វិញ
        $token = $request->bearerToken() ?? $request->header('X-Admin-Token');

        // បើសិនជាអត់មានបោះ Token មកទាំង ២ ផ្លូវ
        if (blank($token)) {
            return response()->json(['message' => 'Unauthorized. Token is missing.'], 401);
        }

        // 🎯 យក Token ទៅ Hash រួចស្វែងរកអ្នកប្រើប្រាស់ក្នុង Database
        $user = User::query()
            ->where('admin_api_token_hash', hash('sha256', $token))
            ->first();

        // បើសិនជាកូដ Hash មិនត្រូវគ្នាជាមួយទិន្នន័យក្នុង Database (Token ក្លែងក្លាយ ឬខុស)
        if (! $user) {
            return response()->json(['message' => 'Unauthorized. Invalid token.'], 401);
        }

        // 🔐 បើត្រឹមត្រូវ វាយត្រាអនុញ្ញាតឱ្យចូលប្រព័ន្ធ
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}