<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AgentAttachmentController;
use App\Http\Controllers\Agent\AgentMessageController;
use App\Http\Controllers\Agent\ChatbotController;
use App\Http\Controllers\Agent\ContactController;
use App\Http\Controllers\Agent\ConversationController;
use App\Http\Controllers\Agent\DepartmentController;
use App\Http\Controllers\Agent\KnowledgeController;
use App\Http\Controllers\Agent\WidgetSettingsController;
use App\Http\Controllers\AttachmentStreamController;
use App\Http\Controllers\Auth\IssueApiTokenController;
use App\Http\Controllers\Widget\WidgetAttachmentController;
use App\Http\Controllers\Widget\WidgetConversationController;
use App\Http\Controllers\Widget\WidgetIdentifyController;
use App\Http\Controllers\Widget\WidgetMessageController;
use App\Http\Controllers\Widget\WidgetSessionController;
use App\Http\Middleware\EstablishTenantContext;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatewayTokenIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Unauthenticated: token issuance + widget bootstrap (rate-limited in controllers).
Route::post('/auth/token', IssueApiTokenController::class);
Route::post('/widget/session', WidgetSessionController::class);

// Signed attachment stream (§14): the temporary signature IS the auth —
// minted only by the authorized download endpoints, short expiry.
Route::get('/attachments/{attachment}/stream', AttachmentStreamController::class)
    ->middleware('signed')
    ->name('attachments.stream');

// User surface: default Sanctum guard + the implicit '*' ability of
// first-party user tokens — visitor tokens (ability: widget) get 403.
Route::middleware([EstablishTenantContext::class, 'auth:sanctum', 'ability:user-api'])
    ->group(function (): void {
        Route::get('/user', fn (Request $request) => $request->user());

        // Short-lived gateway socket token (ADR-002); identity only.
        Route::post('/gateway-token', function (Request $request, GatewayTokenIssuer $issuer) {
            $user = $request->user();
            abort_unless($user instanceof User, 403);

            return response()->json([
                'token' => $issuer->issueForUser((string) $user->organization_id, $user->id),
                'url' => config('services.gateway.url'),
            ], 201);
        });

        // Agent conversation surface (OpenAPI /conversations).
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
        Route::get('/conversations/{conversation}/messages', [AgentMessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [AgentMessageController::class, 'store']);
        Route::post('/conversations/{conversation}/attachments', [AgentAttachmentController::class, 'store']);
        Route::get('/conversations/{conversation}/attachments/{attachment}/download', [AgentAttachmentController::class, 'download']);

        Route::get('/chatbots', [ChatbotController::class, 'index']);
        Route::post('/chatbots', [ChatbotController::class, 'store']);
        Route::get('/chatbots/{chatbot}', [ChatbotController::class, 'show']);
        Route::patch('/chatbots/{chatbot}', [ChatbotController::class, 'update']);

        Route::get('/organization/widget-settings', [WidgetSettingsController::class, 'show']);
        Route::post('/organization/widget-settings/rotate-secret', [WidgetSettingsController::class, 'rotateSecret']);

        Route::get('/knowledge/documents', [KnowledgeController::class, 'index']);
        Route::post('/knowledge/documents', [KnowledgeController::class, 'store']);
        Route::delete('/knowledge/documents/{document}', [KnowledgeController::class, 'destroy']);

        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::patch('/departments/{department}', [DepartmentController::class, 'update']);
        Route::put('/departments/{department}/members', [DepartmentController::class, 'setMembers']);
        Route::get('/users', function () {
            return response()->json([
                'data' => User::query()->orderBy('name')->limit(200)
                    ->get(['id', 'name', 'email'])
                    ->map(fn ($user) => ['user_id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                    ->all(),
            ]);
        });

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
        Route::post('/conversations/{conversation}/attachments', [WidgetAttachmentController::class, 'store']);
        Route::get('/conversations/{conversation}/attachments/{attachment}/download', [WidgetAttachmentController::class, 'download']);
        Route::post('/identify', WidgetIdentifyController::class);
        Route::post('/gateway-token', function (Request $request, GatewayTokenIssuer $issuer) {
            $visitor = $request->user('widget');
            abort_unless($visitor instanceof Visitor, 403);

            return response()->json([
                'token' => $issuer->issueForVisitor((string) $visitor->organization_id, $visitor->id),
                'url' => config('services.gateway.url'),
            ], 201);
        });
    });
