<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\ValidarSesionUnica::class,
        ]);

        // Configuración para Easypanel/Docker (Reverse Proxy)
        $middleware->trustProxies(at: '*');

        $middleware->validateCsrfTokens(except: [
            'logout',
        ]);

        $middleware->alias([
            'password.change.check' => \App\Http\Middleware\EnsurePasswordChanged::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Sesión expirada (token CSRF) → redirigir al login
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            $wantsJson = $request->expectsJson() || $request->is('api/*') || $request->ajax() || strtolower($request->header('X-Requested-With')) === 'xmlhttprequest';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => 'Sesión expirada por inactividad.', 'redirect' => '/login'], 419);
            }
            // Retornamos directamente un relative redirect o a /login previniendo pérdida de puerto local
            return redirect('/login')->with('info', 'La sesión ha caducado por seguridad. Por favor, inicie sesión nuevamente.');
        });

        // Usuario no autenticado en rutas WEB → redirigir al login (nunca mostrar JSON roto en front)
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            $wantsJson = $request->expectsJson() || $request->is('api/*') || $request->ajax() || strtolower($request->header('X-Requested-With')) === 'xmlhttprequest';
            if ($wantsJson) {
                return response()->json(['success' => false, 'error' => 'No autenticado.', 'redirect' => '/login'], 401);
            }
            // Para cualquier otra petición (web, navegador celular) → login
            return redirect('/login')->with('info', 'Tu sesión ha expirado. Por favor, inicia sesión nuevamente.');
        });

        // Acceso denegado (middleware can:* / Gate::denies / authorize()) en rutas WEB.
        // Sin este handler, Laravel muestra la pagina Symfony fea (o pantalla en blanco
        // si la navegacion es via SPA fetch). Retornamos JSON estandar para AJAX y
        // redirect con flash toast para navegacion normal.
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            $wantsJson = $request->expectsJson() || $request->is('api/*') || $request->ajax() || strtolower($request->header('X-Requested-With')) === 'xmlhttprequest';
            // Traducimos SIEMPRE a mensaje amigable. El default de Laravel es
            // "This action is unauthorized." (ingles, generico, confunde al
            // usuario). Solo respetamos el message custom si NO es ese default.
            $raw = $e->getMessage();
            $msg = ($raw && strcasecmp($raw, 'This action is unauthorized.') !== 0)
                ? $raw
                : 'No tienes permiso para realizar esta acción.';
            if ($wantsJson) {
                return response()->json(['success' => false, 'message' => $msg, 'forbidden' => true], 403);
            }
            // Navegacion normal: vuelve a la pagina anterior con flash toast que se
            // mostrara via el hook de estructura_base.blade.php (vidalsa_flash_toast).
            return redirect()->back()->with('flash_toast', [
                'message' => $msg,
                'type'    => 'error',
            ]);
        });

    })->create();
