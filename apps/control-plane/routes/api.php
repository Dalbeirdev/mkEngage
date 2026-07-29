<?php

declare(strict_types=1);

use App\Http\Controllers\Agent\AgentAttachmentController;
use App\Http\Controllers\Agent\AgentMessageController;
use App\Http\Controllers\Agent\AvailabilityController;
use App\Http\Controllers\Agent\CannedResponseController;
use App\Http\Controllers\Agent\ChannelController;
use App\Http\Controllers\Agent\ChatbotController;
use App\Http\Controllers\Agent\ChatbotFlowController;
use App\Http\Controllers\Agent\ContactController;
use App\Http\Controllers\Agent\ContactNoteController;
use App\Http\Controllers\Agent\ConversationAssistController;
use App\Http\Controllers\Agent\ConversationController;
use App\Http\Controllers\Agent\ConversationNoteController;
use App\Http\Controllers\Agent\DepartmentController;
use App\Http\Controllers\Agent\DeveloperController;
use App\Http\Controllers\Agent\InsightsController;
use App\Http\Controllers\Agent\KnowledgeController;
use App\Http\Controllers\Agent\LiveVisitorController;
use App\Http\Controllers\Agent\ModerationController;
use App\Http\Controllers\Agent\OrganizationLogoController;
use App\Http\Controllers\Agent\ProfileController;
use App\Http\Controllers\Agent\TwoFactorController;
use App\Http\Controllers\Agent\WidgetSettingsController;
use App\Http\Controllers\AttachmentStreamController;
use App\Http\Controllers\Auth\IssueApiTokenController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Channels\MessengerWebhookController;
use App\Http\Controllers\Channels\TelegramWebhookController;
use App\Http\Controllers\Channels\WhatsAppWebhookController;
use App\Http\Controllers\MarketingLeadController;
use App\Http\Controllers\Widget\WidgetAttachmentController;
use App\Http\Controllers\Widget\WidgetConversationController;
use App\Http\Controllers\Widget\WidgetHeartbeatController;
use App\Http\Controllers\Widget\WidgetIdentifyController;
use App\Http\Controllers\Widget\WidgetMessageController;
use App\Http\Controllers\Widget\WidgetProfileController;
use App\Http\Controllers\Widget\WidgetRatingController;
use App\Http\Controllers\Widget\WidgetSessionController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EstablishTenantContext;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatewayTokenIssuer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Unauthenticated: token issuance + widget bootstrap (rate-limited in controllers).
Route::post('/auth/token', IssueApiTokenController::class);
Route::post('/auth/register', RegisterController::class);
Route::post('/widget/session', WidgetSessionController::class);

// Public marketing-site leads (contact form + newsletter): unauthenticated,
// IP rate-limited in the controller.
Route::post('/contact', [MarketingLeadController::class, 'contact']);
Route::post('/newsletter', [MarketingLeadController::class, 'subscribe']);

// Public org logo (Phase 30): brand asset shown to every widget visitor.
Route::get('/organizations/{organization}/logo', [OrganizationLogoController::class, 'show']);

// WhatsApp Cloud API webhook (Phase 29): public; Meta authenticates via the
// verify-token handshake (GET) and HMAC signature (POST).
Route::get('/channels/whatsapp/{organization}/{channel}', [WhatsAppWebhookController::class, 'verify']);
Route::post('/channels/whatsapp/{organization}/{channel}', [WhatsAppWebhookController::class, 'receive']);

// Telegram Bot API webhook (Phase 31): secret-token header authenticated.
Route::post('/channels/telegram/{organization}/{channel}', [TelegramWebhookController::class, 'receive']);

// Facebook Messenger webhook (Phase 32): same Meta mechanics as WhatsApp.
Route::get('/channels/messenger/{organization}/{channel}', [MessengerWebhookController::class, 'verify']);
Route::post('/channels/messenger/{organization}/{channel}', [MessengerWebhookController::class, 'receive']);

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

        // The agent's own profile (account, sessions, activity feed, edits).
        Route::get('/profile', [ProfileController::class, 'show']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/password', [ProfileController::class, 'changePassword']);

        // Two-factor auth (TOTP): enroll → confirm, plus recovery + disable.
        Route::post('/profile/2fa/enroll', [TwoFactorController::class, 'enroll']);
        Route::post('/profile/2fa/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('/profile/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::delete('/profile/2fa', [TwoFactorController::class, 'disable']);

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
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/visitors/live', [LiveVisitorController::class, 'index']);
        Route::get('/chatbots/{chatbot}/flow', [ChatbotFlowController::class, 'show']);
        Route::put('/chatbots/{chatbot}/flow', [ChatbotFlowController::class, 'update']);
        Route::get('/channels', [ChannelController::class, 'index']);
        Route::post('/channels', [ChannelController::class, 'store']);
        Route::delete('/channels/{channel}', [ChannelController::class, 'destroy']);
        Route::post('/channels/{channel}/register-webhook', [ChannelController::class, 'registerWebhook']);
        Route::get('/canned-responses', [CannedResponseController::class, 'index']);
        Route::post('/canned-responses', [CannedResponseController::class, 'store']);
        Route::put('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'update']);
        Route::delete('/canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::patch('/conversations/{conversation}', [ConversationController::class, 'update']);
        Route::get('/conversations/{conversation}/messages', [AgentMessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [AgentMessageController::class, 'store']);
        Route::post('/conversations/{conversation}/messages/{message}/reaction', [AgentMessageController::class, 'react']);
        Route::post('/conversations/{conversation}/assign', [ConversationController::class, 'assign']);
        Route::post('/conversations/{conversation}/read', [ConversationController::class, 'markRead']);
        Route::post('/conversations/{conversation}/assist', [ConversationAssistController::class, 'suggest']);
        Route::get('/conversations/{conversation}/notes', [ConversationNoteController::class, 'index']);
        Route::post('/conversations/{conversation}/notes', [ConversationNoteController::class, 'store']);
        Route::post('/conversations/{conversation}/attachments', [AgentAttachmentController::class, 'store']);
        Route::get('/conversations/{conversation}/attachments/{attachment}/download', [AgentAttachmentController::class, 'download']);

        Route::get('/chatbots', [ChatbotController::class, 'index']);
        Route::post('/chatbots', [ChatbotController::class, 'store']);
        Route::get('/chatbots/{chatbot}', [ChatbotController::class, 'show']);
        Route::patch('/chatbots/{chatbot}', [ChatbotController::class, 'update']);

        Route::post('/organization/logo', [OrganizationLogoController::class, 'store']);
        Route::delete('/organization/logo', [OrganizationLogoController::class, 'destroy']);
        Route::get('/organization/widget-settings', [WidgetSettingsController::class, 'show']);
        Route::put('/organization/widget-settings', [WidgetSettingsController::class, 'update']);
        Route::post('/organization/widget-settings/rotate-secret', [WidgetSettingsController::class, 'rotateSecret']);

        // Moderation (abuse controls): profanity filter config + IP ban list.
        Route::get('/moderation', [ModerationController::class, 'show']);
        Route::put('/moderation', [ModerationController::class, 'update']);
        Route::post('/moderation/ip-bans', [ModerationController::class, 'storeBan']);
        Route::delete('/moderation/ip-bans/{ipBan}', [ModerationController::class, 'destroyBan']);

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
        Route::post('/contacts', [ContactController::class, 'store']);
        // Static segments before the {contact} wildcard so they aren't captured.
        Route::get('/contacts/export', [ContactController::class, 'export']);
        Route::post('/contacts/import', [ContactController::class, 'import']);
        Route::get('/contacts/{contact}', [ContactController::class, 'show']);
        Route::get('/contacts/{contact}/notes', [ContactNoteController::class, 'index']);
        Route::post('/contacts/{contact}/notes', [ContactNoteController::class, 'store']);

        // Agent availability (routing v2): the caller's own available/away state.
        Route::get('/me/availability', [AvailabilityController::class, 'show']);
        Route::patch('/me/availability', [AvailabilityController::class, 'update']);

        // mkEngage Insights (tenant-scoped analytics overview).
        Route::get('/insights/overview', [InsightsController::class, 'overview']);

        // Developer platform (Phase 35, §15).
        Route::get('/api-keys', [DeveloperController::class, 'listKeys']);
        Route::post('/api-keys', [DeveloperController::class, 'createKey']);
        Route::delete('/api-keys/{apiKey}', [DeveloperController::class, 'revokeKey']);
        Route::get('/webhook-endpoints', [DeveloperController::class, 'listWebhooks']);
        Route::post('/webhook-endpoints', [DeveloperController::class, 'createWebhook']);
        Route::delete('/webhook-endpoints/{webhookEndpoint}', [DeveloperController::class, 'deleteWebhook']);
        Route::post('/webhook-endpoints/{webhookEndpoint}/test', [DeveloperController::class, 'testWebhook']);
    });

// Machine API (Phase 35, §15): scoped mk_live_ keys, read-only v1 surface.
Route::middleware([AuthenticateApiKey::class])
    ->prefix('v1')
    ->group(function (): void {
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::get('/conversations/{conversation}/messages', [AgentMessageController::class, 'index']);
        Route::get('/contacts', [ContactController::class, 'index']);
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
        Route::post('/conversations/{conversation}/messages/{message}/reaction', [WidgetMessageController::class, 'react']);
        Route::post('/conversations/{conversation}/attachments', [WidgetAttachmentController::class, 'store']);
        Route::get('/conversations/{conversation}/attachments/{attachment}/download', [WidgetAttachmentController::class, 'download']);
        Route::post('/identify', WidgetIdentifyController::class);
        Route::post('/profile', WidgetProfileController::class);
        Route::post('/heartbeat', WidgetHeartbeatController::class);
        Route::post('/conversations/{conversation}/rating', WidgetRatingController::class);
        Route::post('/gateway-token', function (Request $request, GatewayTokenIssuer $issuer) {
            $visitor = $request->user('widget');
            abort_unless($visitor instanceof Visitor, 403);

            return response()->json([
                'token' => $issuer->issueForVisitor((string) $visitor->organization_id, $visitor->id),
                'url' => config('services.gateway.url'),
            ], 201);
        });
    });
