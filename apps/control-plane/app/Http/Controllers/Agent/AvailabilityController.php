<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent availability (§16 routing v2): an agent toggles their own
 * available/away state and optional concurrency cap. Only `available` agents
 * receive auto-assignments. Agents may only change their OWN state.
 */
final class AvailabilityController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        return response()->json($this->toContract($agent));
    }

    public function update(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $validated = $request->validate([
            'availability' => ['sometimes', 'in:available,away'],
            'max_open_conversations' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        if (array_key_exists('availability', $validated)) {
            $agent->availability = $validated['availability'];
        }
        if (array_key_exists('max_open_conversations', $validated)) {
            $agent->max_open_conversations = $validated['max_open_conversations'];
        }
        $agent->save();

        return response()->json($this->toContract($agent));
    }

    private function agent(Request $request): User
    {
        $agent = $request->user();
        abort_unless($agent instanceof User, 403);

        return $agent;
    }

    /** @return array<string, mixed> */
    private function toContract(User $agent): array
    {
        return [
            'availability' => $agent->availability,
            'max_open_conversations' => $agent->max_open_conversations,
        ];
    }
}
