<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Chatbot;
use App\Models\Organization;
use App\Models\User;
use App\Services\PlanService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chatbot administration (§2 chatbot configuration). One ACTIVE bot per
 * organization for now (the widget attaches "the active bot" to new
 * conversations): activating a bot pauses the others atomically. Granular
 * permissions (chatbots.manage) arrive with the roles UI; any active user
 * may manage bots meanwhile — consistent with the rest of the agent surface.
 */
final class ChatbotController extends Controller
{
    private const PROVIDERS = ['fake', 'openai', 'anthropic', 'gemini'];

    public function index(): JsonResponse
    {
        $chatbots = Chatbot::query()->orderBy('created_at')->get();

        return response()->json([
            'data' => $chatbots->map(fn (Chatbot $chatbot): array => $this->toContract($chatbot))->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        app(PlanService::class)->assertCanCreate(
            Organization::query()->whereKey($context->organizationId())->firstOrFail(),
            'chatbots',
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'provider' => ['sometimes', 'in:'.implode(',', self::PROVIDERS)],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $chatbot = Chatbot::query()->create([
            'name' => $validated['name'],
            'status' => 'draft',
            'system_prompt' => $validated['system_prompt'] ?? null,
            'provider' => $validated['provider'] ?? 'fake',
            'model' => $validated['model'] ?? null,
        ]);

        $this->audit($request, 'chatbot.created', $chatbot);

        return response()->json($this->toContract($chatbot), 201);
    }

    public function show(string $chatbotId): JsonResponse
    {
        return response()->json($this->toContract($this->find($chatbotId)));
    }

    public function update(Request $request, string $chatbotId): JsonResponse
    {
        $chatbot = $this->find($chatbotId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'in:draft,active,paused'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'provider' => ['sometimes', 'in:'.implode(',', self::PROVIDERS)],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        // Single-active invariant: activating this bot pauses every other.
        if (($validated['status'] ?? null) === 'active') {
            Chatbot::query()
                ->whereKeyNot($chatbot->id)
                ->where('status', 'active')
                ->update(['status' => 'paused']);
        }

        $chatbot->fill($validated)->save();

        $this->audit($request, 'chatbot.updated', $chatbot, array_map('strval', array_keys($validated)));

        return response()->json($this->toContract($chatbot));
    }

    private function find(string $chatbotId): Chatbot
    {
        $chatbot = Chatbot::query()->find($chatbotId);
        abort_if($chatbot === null, 404);

        return $chatbot;
    }

    /** @param list<string> $fields */
    private function audit(Request $request, string $action, Chatbot $chatbot, array $fields = []): void
    {
        $user = $request->user();

        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: $action,
            subject: $chatbot,
            context: $fields === [] ? [] : ['fields' => $fields],
            ip: $request->ip(),
        );
    }

    /** @return array<string, mixed> */
    private function toContract(Chatbot $chatbot): array
    {
        return [
            'chatbot_id' => $chatbot->id,
            'name' => $chatbot->name,
            'status' => $chatbot->status,
            'system_prompt' => $chatbot->system_prompt,
            'provider' => $chatbot->provider,
            'model' => $chatbot->model,
            'created_at' => $chatbot->created_at?->toIso8601String(),
            'updated_at' => $chatbot->updated_at?->toIso8601String(),
        ];
    }
}
