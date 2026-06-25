<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 🎯 ដំណោះស្រាយពិសេស៖ បើសិនជាមាន Preflight OPTIONS Request ហៅមកពី Frontend
        if ($request->isMethod('OPTIONS')) {
            return response('', 200)
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization, X-Requested-With, X-FT-API-ID, X-FT-Timestamp, X-FT-Nonce, X-FT-Signature');
        }

        $response = $next($request);

        // បន្ថែម Header CORS ទៅកាន់គ្រប់ Response ធម្មតាទាំងអស់ដែលបោះទៅ Frontend
        if (method_exists($response, 'header')) {
            return $response
                ->header('Access-Control-Allow-Origin', '*')
                ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->header('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization, X-Requested-With, X-FT-API-ID, X-FT-Timestamp, X-FT-Nonce, X-FT-Signature');
        }

        return $response;
    }
}