<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An agent's own saved inbox views. RLS scopes to the org; every query is
 * further narrowed to the caller's user_id so agents never see each other's
 * views.
 */
final class SavedViewController extends Controller
{
    private const MAX_PER_USER = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $views = SavedView::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $views->map(fn (SavedView $view): array => $view->toContract())->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'filters' => ['required', 'array:tab,channel,priority,department_id,search'],
            'filters.tab' => ['sometimes', 'nullable', 'in:all,open,pending,closed,unassigned,spam'],
            'filters.channel' => ['sometimes', 'nullable', 'string', 'max:20'],
            'filters.priority' => ['sometimes', 'nullable', 'string', 'max:10'],
            'filters.department_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        abort_if(
            SavedView::query()->where('user_id', $user->id)->count() >= self::MAX_PER_USER,
            422,
            'You have reached the maximum number of saved views.',
        );

        // Keep only recognized, non-null filter keys.
        $filters = array_filter(
            $validated['filters'],
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $view = SavedView::query()->create([
            'user_id' => $user->id,
            'name' => $validated['name'],
            'filters' => $filters,
        ]);

        return response()->json($view->toContract(), 201);
    }

    public function destroy(Request $request, string $savedView): JsonResponse
    {
        $user = $this->user($request);

        // Own-only: scoping by user_id makes another agent's id a 404.
        $view = SavedView::query()->whereKey($savedView)->where('user_id', $user->id)->firstOrFail();
        $view->delete();

        return response()->json(null, 204);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
