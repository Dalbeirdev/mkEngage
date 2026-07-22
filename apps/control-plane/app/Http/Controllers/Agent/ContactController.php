<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Agent contact surface (OpenAPI /contacts — read side for Phase 5). */
final class ContactController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['sometimes', 'email'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $contacts = Contact::query()
            ->when(
                isset($validated['email']),
                fn ($query) => $query->where('email', $validated['email']),
            )
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 50)
            ->get();

        return response()->json([
            'data' => $contacts->map(fn (Contact $contact): array => $contact->toContract())->all(),
        ]);
    }

    public function show(string $contactId): JsonResponse
    {
        $contact = Contact::query()->find($contactId);
        abort_if($contact === null, 404);

        return response()->json($contact->toContract());
    }
}
