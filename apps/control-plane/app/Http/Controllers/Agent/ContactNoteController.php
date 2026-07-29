<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Agent notes on a CRM contact. RLS scopes every query to the caller's org.
 */
final class ContactNoteController extends Controller
{
    public function index(string $contactId): JsonResponse
    {
        $this->contact($contactId);

        $notes = ContactNote::query()
            ->with('author:id,name')
            ->where('contact_id', $contactId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $notes->map(fn (ContactNote $note): array => $note->toContract())->all(),
        ]);
    }

    public function store(Request $request, string $contactId): JsonResponse
    {
        $contact = $this->contact($contactId);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
        ]);

        $agent = $request->user();
        abort_unless($agent instanceof User, 403);

        $note = ContactNote::query()->create([
            'contact_id' => $contact->id,
            'author_id' => $agent->id,
            'body' => $validated['body'],
        ]);

        return response()->json($note->load('author:id,name')->toContract(), 201);
    }

    private function contact(string $contactId): Contact
    {
        $contact = Contact::query()->find($contactId);
        abort_if($contact === null, 404);

        return $contact;
    }
}
