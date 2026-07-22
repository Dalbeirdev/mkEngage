<?php

use App\Http\Middleware\EstablishTenantContext;
use Illuminate\Auth\Middleware\Authenticate;
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
        // Tenant context MUST be established before authentication: Sanctum's
        // user lookup reads the RLS-protected users table (see the
        // EstablishTenantContext docblock). Without this priority entry the
        // sorter runs Authenticate first.
        $middleware->priority([
            EstablishTenantContext::class,
            Authenticate::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
