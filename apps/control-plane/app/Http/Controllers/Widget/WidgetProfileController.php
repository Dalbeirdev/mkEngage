<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pre-chat profile capture (Phase 23): the visitor self-reports a name and
 * optional email before the first message. UNVERIFIED data by design — unlike
 * /identify there is no HMAC, so the contact is created/linked as a plain
 * lead record and never trusted for account access. Tenant scope is enforced
 * upstream (RLS + global scope); no cross-org contact can be matched.
 */
final class WidgetProfileController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $visitor = $request->user('widget');
        abort_unless($visitor instanceof Visitor, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);

        $visitor->display_name = $validated['name'];

        $email = $validated['email'] ?? null;
        if (is_string($email) && $email !== '' && $visitor->contact_id === null) {
            // Lead capture: reuse the org's contact for this email or create
            // one. Never overwrites an existing verified contact link.
            $contact = Contact::query()->firstOrCreate(
                ['email' => mb_strtolower($email)],
                ['name' => $validated['name']],
            );
            $visitor->contact_id = $contact->id;
        }

        $visitor->save();

        return response()->json([
            'display_name' => $visitor->display_name,
            'contact_id' => $visitor->contact_id,
        ]);
    }
}
