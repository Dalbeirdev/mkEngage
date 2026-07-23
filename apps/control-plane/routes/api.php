<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AgentMessageController;
use App\Http\Controllers\Agent\ChatbotController;
use App\Http\Controllers\Agent\ContactController;
use App\Http\Controllers\Agent\ConversationController;
use App\Http\Controllers\Agent\WidgetSettingsController;
use App\Http\Controllers\Auth\IssueApiTokenController;
use App\Http\Controllers\Widget\WidgetConversationController;
use App\Http\Controllers\Widget\WidgetIdentifyController;
use App\Http\Controllers\Widget\WidgetMessageController;
use App\Http\Controllers\Widget\WidgetSessionController;
use App\Http\Middleware\EstablishTenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Unauthenticated: token issuance + widget bootstrap (rate-limited in controllers).
Route::post('/auth/token', IssueApiTokenController::class);
Route::post('/widget/session', WidgetSessionController::class);

// User surface: default Sanctum guard + the implicit '*' ability of
// first-party user tokens — visitor tokens (ability: widget) get 403.
Route::middleware([EstablishTenantContext::class, 'auth:sanctum', 'ability:user-api'])
    ->group(function (): void {
        Route::get('/user', fn (Request $request) => $request->user());

        // Agent conversation surface (OpenAPI /conversations).
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
        Route::get('/conversations/{conversation}/messages', [AgentMessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [AgentMessageController::class, 'store']);

        Route::get('/chatbots', [ChatbotController::class, 'index']);
        Route::post('/chatbots', [ChatbotController::class, 'store']);
        Route::get('/chatbots/{chatbot}', [ChatbotController::class, 'show']);
        Route::patch('/chatbots/{chatbot}', [ChatbotController::class, 'update']);

        Route::get('/organization/widget-settings', [WidgetSettingsController::class, 'show']);
        Route::post('/organization/widget-settings/rotate-secret', [WidgetSettingsController::class, 'rotateSecret']);

        Route::get('/contacts', [ContactController::class, 'index']);
        Route::get('/contacts/{contact}', [ContactController::class, 'show']);
    });

// Visitor-facing widget API: the `widget` guard authenticates ONLY Visitor
// tokenables (provider-restricted), and tokens must carry the "widget"
// ability. Tenant context still comes first (see middleware docblock).
Route::middleware([EstablishTenantContext::class, 'auth:widget', 'ability:widget'])
    ->prefix('widget')
    ->group(function (): void {
        Route::post('/conversations', [WidgetConversationController::class, 'store']);
        Route::get('/conversations/{conversation}', [WidgetConversationController::class, 'show']);
        Route::get('/conversations/{conversation}/messages', [WidgetMessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [WidgetMessageController::class, 'store']);
        Route::post('/identify', WidgetIdentifyController::class);
    });
