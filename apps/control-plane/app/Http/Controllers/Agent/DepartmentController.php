<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Department administration (§2 departments, ASSUMPTIONS A5). One DEFAULT
 * department per org receives new widget conversations; setting a new
 * default atomically clears the previous one (single-default invariant,
 * same pattern as the single-active chatbot).
 */
final class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = Department::query()
            ->withCount('users')
            ->with('users:id')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $departments->map(
                fn (Department $department): array => $this->toContract($department),
            )->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $isDefault = (bool) ($validated['is_default'] ?? false);

        // First department becomes the default automatically — routing always
        // has a target once any department exists.
        if (! $isDefault && ! Department::query()->exists()) {
            $isDefault = true;
        }

        if ($isDefault) {
            Department::query()->where('is_default', true)->update(['is_default' => false]);
        }

        $department = Department::query()->create([
            'name' => $validated['name'],
            'is_default' => $isDefault,
        ]);

        $this->audit($request, 'department.created', $department);

        return response()->json($this->toContract($department->load('users:id')->loadCount('users')), 201);
    }

    public function update(Request $request, string $departmentId): JsonResponse
    {
        $department = $this->find($departmentId);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (($validated['is_default'] ?? false) === true) {
            Department::query()
                ->whereKeyNot($department->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $department->fill($validated)->save();

        $this->audit($request, 'department.updated', $department);

        return response()->json($this->toContract($department->load('users:id')->loadCount('users')));
    }

    /** Replace the member set (PUT semantics — the picker sends the full list). */
    public function setMembers(Request $request, string $departmentId): JsonResponse
    {
        $department = $this->find($departmentId);

        $validated = $request->validate([
            'user_ids' => ['present', 'array', 'max:200'],
            'user_ids.*' => ['uuid'],
        ]);

        // Tenant scope: only users visible under RLS/app scope can be attached.
        $users = User::query()->whereIn('id', $validated['user_ids'])->pluck('id');

        $memberPivots = [];
        foreach ($users as $id) {
            if (is_string($id)) {
                $memberPivots[$id] = ['organization_id' => $department->organization_id];
            }
        }
        $department->users()->sync($memberPivots);

        $this->audit($request, 'department.members.updated', $department, [
            'member_count' => $users->count(),
        ]);

        return response()->json($this->toContract($department->load('users:id')->loadCount('users')));
    }

    private function find(string $departmentId): Department
    {
        $department = Department::query()->find($departmentId);
        abort_if($department === null, 404);

        return $department;
    }

    /** @param array<string, mixed> $context */
    private function audit(Request $request, string $action, Department $department, array $context = []): void
    {
        $user = $request->user();

        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: $action,
            subject: $department,
            context: $context,
            ip: $request->ip(),
        );
    }

    /** @return array<string, mixed> */
    private function toContract(Department $department): array
    {
        return [
            'department_id' => $department->id,
            'name' => $department->name,
            'is_default' => (bool) $department->is_default,
            'member_count' => (int) ($department->users_count ?? 0),
            'member_ids' => $department->relationLoaded('users')
                ? $department->users->pluck('id')->all()
                : [],
            'created_at' => $department->created_at?->toIso8601String(),
        ];
    }
}
