<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\AuditLogEntry;
use App\Models\Organization;
use App\Models\Visitor;
use App\Services\VisitorIdentifier;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/widget/identify — link the authenticated visitor to a contact
 * via a customer-backend-signed identity payload (VisitorIdentifier).
 * Invalid signatures return the same 422 whether the org has identity
 * configured, the signature is wrong, or the contact exists — no oracle.
 */
final class WidgetIdentifyController extends Controller
{
    public function __invoke(
        Request $request,
        VisitorIdentifier $identifier,
        TenantContext $context,
    ): JsonResponse {
        $visitor = $request->user('widget');
        abort_unless($visitor instanceof Visitor, 403);

        $validated = $request->validate([
            'external_id' => ['required', 'string', 'max:100'],
            'signature' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $organization = Organization::query()
            ->whereKey($context->organizationId())
            ->firstOrFail();

        $contact = $identifier->identify($organization, $visitor, [
            'external_id' => $validated['external_id'],
            'signature' => $validated['signature'],
            'email' => $validated['email'] ?? null,
            'name' => $validated['name'] ?? null,
        ]);

        if ($contact === null) {
            return response()->json([
                'title' => 'Identity verification failed',
                'status' => 422,
            ], 422, ['Content-Type' => 'application/problem+json']);
        }

        AuditLogEntry::record(
            actor: 'visitor:'.$visitor->id,
            action: 'visitor.identified',
            subject: $contact,
            context: ['method' => 'signed_identity'],
            ip: $request->ip(),
        );

        return response()->json([
            'contact_id' => $contact->id,
            'display_name' => $contact->name,
        ]);
    }
}
