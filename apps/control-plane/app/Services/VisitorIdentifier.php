<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Organization;
use App\Models\Visitor;

/**
 * Verified visitor identity (ADR-009 boundary 2, ASSUMPTIONS A4).
 *
 * The customer's BACKEND computes signature = HMAC-SHA256(external_id,
 * org.widget_signing_secret) and embeds it in the page (SDK helpers in
 * integrations/*). The widget forwards it untouched — it can never mint one
 * because the secret never reaches the browser (§4). Verification fails
 * closed: bad/missing signature ⇒ identification rejected, visitor stays
 * anonymous, nothing leaks about whether the external_id exists.
 *
 * A verified identity finds-or-creates the contact by external_id, links the
 * visitor, and back-fills contact_id onto the visitor's conversations.
 */
final class VisitorIdentifier
{
    /** @param array{external_id: string, signature: string, email?: string|null, name?: string|null} $identity */
    public function identify(Organization $organization, Visitor $visitor, array $identity): ?Contact
    {
        $secret = $organization->widget_signing_secret;

        if ($secret === null || $secret === '') {
            return null; // Identity not configured for this org — fail closed.
        }

        $expected = hash_hmac('sha256', $identity['external_id'], $secret);

        if (! hash_equals($expected, strtolower($identity['signature']))) {
            return null;
        }

        $contact = Contact::query()->firstOrCreate(
            ['external_id' => $identity['external_id']],
            [
                'email' => $identity['email'] ?? null,
                'name' => $identity['name'] ?? null,
            ],
        );

        // Verified payloads may refresh profile basics.
        $dirty = false;
        if (($identity['email'] ?? null) !== null && $contact->email !== $identity['email']) {
            $contact->email = $identity['email'];
            $dirty = true;
        }
        if (($identity['name'] ?? null) !== null && $contact->name !== $identity['name']) {
            $contact->name = $identity['name'];
            $dirty = true;
        }
        if ($dirty) {
            $contact->save();
        }

        $visitor->contact_id = $contact->id;
        if ($contact->name !== null) {
            $visitor->display_name = $contact->name;
        }
        $visitor->save();

        Conversation::query()
            ->where('visitor_id', $visitor->id)
            ->whereNull('contact_id')
            ->update(['contact_id' => $contact->id]);

        return $contact;
    }
}
