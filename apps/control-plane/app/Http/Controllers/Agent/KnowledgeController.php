<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Jobs\IngestKnowledgeDocument;
use App\Models\AuditLogEntry;
use App\Models\KnowledgeDocument;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Knowledge document administration (§2 knowledge-source administration). */
final class KnowledgeController extends Controller
{
    public function index(): JsonResponse
    {
        $documents = KnowledgeDocument::query()->orderByDesc('created_at')->limit(100)->get();

        return response()->json([
            'data' => $documents->map(
                fn (KnowledgeDocument $document): array => $this->toContract($document),
            )->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000'],
        ]);

        $document = KnowledgeDocument::query()->create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'status' => 'pending',
        ]);

        // §2: chunking/embedding never runs in the request.
        IngestKnowledgeDocument::dispatch(
            (string) $document->organization_id,
            $document->id,
        )->afterCommit();

        $this->audit($request, 'knowledge.document.created', $document);

        return response()->json($this->toContract($document), 201);
    }

    public function destroy(Request $request, string $documentId): JsonResponse
    {
        $document = KnowledgeDocument::query()->find($documentId);
        abort_if($document === null, 404);

        $document->delete(); // chunks cascade

        $this->audit($request, 'knowledge.document.deleted', $document);

        return response()->json(['status' => 'deleted']);
    }

    private function audit(Request $request, string $action, KnowledgeDocument $document): void
    {
        $user = $request->user();

        AuditLogEntry::record(
            actor: $user instanceof User ? 'user:'.$user->id : 'system',
            action: $action,
            subject: $document,
            ip: $request->ip(),
        );
    }

    /** @return array<string, mixed> */
    private function toContract(KnowledgeDocument $document): array
    {
        return [
            'document_id' => $document->id,
            'title' => $document->title,
            'status' => $document->status,
            'chunk_count' => $document->chunk_count,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
