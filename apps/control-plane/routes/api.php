<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\IssueApiTokenController;
use App\Http\Middleware\EstablishTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Unauthenticated: token issuance (rate-limited in controller).
Route::post('/auth/token', IssueApiTokenController::class);

// Everything tenant-scoped: context first, then Sanctum (see middleware docblock).
Route::middleware([EstablishTenantContext::class, 'auth:sanctum'])->group(function (): void {
    Route::get('/user', fn (Request $request) => $request->user());
});
