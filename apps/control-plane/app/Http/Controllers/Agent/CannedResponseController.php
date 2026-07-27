<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\CannedResponse;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Canned response CRUD (Phase 25). Org-wide: any agent can use every
 * template; editing is open to all active agents for now (RBAC refinement
 * arrives with the roles module).
 */
final class CannedResponseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CannedResponse::query()
                ->orderBy('shortcut')
                ->get()
                ->map(fn (CannedResponse $canned): array => $canned->toContract())
                ->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);

        $user = $request->user();
        $canned = CannedResponse::query()->create([
            ...$validated,
            'created_by' => $user instanceof User ? $user->id : null,
        ]);

        return response()->json($canned->toContract(), 201);
    }

    public function update(Request $request, string $cannedResponseId): JsonResponse
    {
        $canned = CannedResponse::query()->find($cannedResponseId);
        abort_if($canned === null, 404);

        $canned->update($this->validated($request, $cannedResponseId));

        return response()->json($canned->toContract());
    }

    public function destroy(string $cannedResponseId): JsonResponse
    {
        $canned = CannedResponse::query()->find($cannedResponseId);
        abort_if($canned === null, 404);

        $canned->delete();

        return response()->json(null, 204);
    }

    /** @return array{title: string, shortcut: string, body: string} */
    private function validated(Request $request, ?string $ignoreId = null): array
    {
        /** @var array{title: string, shortcut: string, body: string} */
        return $request->validate([
            'title' => ['required', 'string', 'max:100'],
            // Lowercase slug so "/shortcut" completion is predictable.
            'shortcut' => [
                'required', 'string', 'max:30', 'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('canned_responses', 'shortcut')
                    ->where('organization_id', app(TenantContext::class)->organizationId())
                    ->ignore($ignoreId),
            ],
            'body' => ['required', 'string', 'max:16000'],
        ]);
    }
}
