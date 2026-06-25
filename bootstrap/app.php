<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        
        // 🔐 រក្សាទុក Alias សម្រាប់ផ្ទាំង Admin ដដែល
        $middleware->alias([
            'admin.token' => \App\Http\Middleware\EnsureAdminApiToken::class,
        ]);

        // 🎯 លើកលែងច្បាប់ CSRF សម្រាប់រាល់ API និង Webhook ទាំងអស់
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'api/khqr-webhook',
            'api/khqr/webhook',
            'api/flashtopup/webhook',
        ]);

        // 🌐 បើកសិទ្ធិ CORS ជាសកល ដើម្បីបំបាត់ CORS Error លើ Browser ទាំងស្រុង
        $middleware->append(function ($request, $next) {
            $response = $next($request);
            
            if (method_exists($response, 'header')) {
                return $response
                    ->header('Access-Control-Allow-Origin', '*')
                    ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                    ->header('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept, Authorization, X-Requested-With, X-FT-API-ID, X-FT-Timestamp, X-FT-Nonce, X-FT-Signature');
            }
            
            return $response;
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();