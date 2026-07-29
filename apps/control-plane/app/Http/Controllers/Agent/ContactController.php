<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Agent contact surface (OpenAPI /contacts — read + CRM management). */
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

    /** Manually add a contact (CRM). Rejects a duplicate email within the org. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $email = isset($validated['email']) && is_string($validated['email'])
            ? Str::lower($validated['email'])
            : null;

        if ($email !== null && Contact::query()->where('email', $email)->exists()) {
            abort(422, 'A contact with this email already exists.');
        }

        $contact = Contact::query()->create([
            'name' => $validated['name'] ?? null,
            'email' => $email,
            'phone' => $validated['phone'] ?? null,
        ]);

        return response()->json($contact->toContract(), 201);
    }

    /**
     * Bulk-import contacts from an uploaded CSV. A header row maps the columns
     * (name/email/phone, case-insensitive, any order). Rows whose email already
     * exists are skipped, so re-importing is safe.
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $file = $request->file('file');
        abort_if($file === null, 422);

        $contents = (string) file_get_contents($file->getRealPath());
        $rows = array_filter(array_map('trim', explode("\n", str_replace("\r", '', $contents))), fn (string $l): bool => $l !== '');
        abort_if($rows === [], 422, 'The file is empty.');

        // First row is the header; map wanted columns to their positions.
        $header = array_map(fn (?string $h): string => Str::lower(trim((string) $h)), str_getcsv((string) array_shift($rows)));
        $col = [
            'name' => array_search('name', $header, true),
            'email' => array_search('email', $header, true),
            'phone' => array_search('phone', $header, true),
        ];
        abort_if($col['name'] === false && $col['email'] === false, 422, 'CSV needs a name or email column.');

        $imported = 0;
        $skipped = 0;
        foreach (array_slice($rows, 0, 5000) as $line) {
            $fields = str_getcsv($line);
            $get = fn (int|false $i): ?string => is_int($i) && isset($fields[$i]) && trim($fields[$i]) !== '' ? trim($fields[$i]) : null;

            $name = $get($col['name']);
            $email = $get($col['email']);
            $email = $email !== null ? Str::lower($email) : null;
            $phone = $get($col['phone']);

            if ($name === null && $email === null) {
                $skipped++;

                continue;
            }
            if ($email !== null && Contact::query()->where('email', $email)->exists()) {
                $skipped++;

                continue;
            }

            Contact::query()->create(['name' => $name, 'email' => $email, 'phone' => $phone]);
            $imported++;
        }

        return response()->json(['imported' => $imported, 'skipped' => $skipped], 201);
    }

    /**
     * Every org contact as a CSV download (all rows, not just a page). Built
     * in-memory within the request so the query runs under the caller's tenant
     * context (a streamed body would execute after that context is torn down).
     */
    public function export(): Response
    {
        $filename = 'contacts-'.now()->format('Y-m-d').'.csv';

        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            throw new \RuntimeException('Could not open the CSV buffer.');
        }
        fputcsv($out, ['name', 'email', 'phone', 'external_id', 'created_at']);

        Contact::query()->orderByDesc('created_at')->chunk(500, function ($contacts) use ($out): void {
            foreach ($contacts as $contact) {
                fputcsv($out, [
                    $contact->name ?? '',
                    $contact->email ?? '',
                    $contact->phone ?? '',
                    $contact->external_id ?? '',
                    $contact->created_at?->toIso8601String() ?? '',
                ]);
            }
        });

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
