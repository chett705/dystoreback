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
        // 🎯 ដំណោះស្រាយត្រឹមត្រូវ៖ បើកចំហរទ្វារសម្រាប់ OPTIONS Preflight Request (បាត់រលកក្រហម និងបាត់គាំង 502)
        if ($request->isMethod('OPTIONS')) {
            $response = new Response('', 200);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization, X-Requested-With, X-FT-API-ID, X-FT-Timestamp, X-FT-Nonce, X-FT-Signature');
            return $response;
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