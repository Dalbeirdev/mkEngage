<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\KnowledgeChunker;
use App\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Knowledge ingestion v1 (§19 chunking + embedding; §2: never in the
 * request): chunk the document, embed via the AI service, store chunks
 * (embedding only when the pgvector column exists — capability-gated like
 * the migration). Embedding failure degrades to FTS-only chunks rather
 * than failing ingestion: retrieval still works on the FTS arm.
 */
final class IngestKnowledgeDocument implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $organizationId,
        private readonly string $documentId,
    ) {}

    public function handle(Tenancy $tenancy, KnowledgeChunker $chunker): void
    {
        $tenancy->run($this->organizationId, function () use ($chunker): void {
            $document = KnowledgeDocument::query()->find($this->documentId);

            if ($document === null) {
                return;
            }

            $chunks = $chunker->chunk($document->body);

            if ($chunks === []) {
                $document->update(['status' => 'failed', 'chunk_count' => 0]);

                return;
            }

            $vectors = $this->embed($chunks);

            // Re-ingestion replaces the chunk set atomically.
            KnowledgeChunk::query()->where('document_id', $document->id)->delete();

            foreach ($chunks as $index => $content) {
                $chunk = KnowledgeChunk::query()->create([
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content' => $content,
                    'content_checksum' => hash('sha256', $content),
                ]);

                if ($vectors !== null && self::hasVectorColumn()) {
                    DB::table('knowledge_chunks')
                        ->where('id', $chunk->id)
                        ->update(['embedding' => '['.implode(',', $vectors[$index]).']']);
                }
            }

            $document->update(['status' => 'ready', 'chunk_count' => count($chunks)]);
        });
    }

    /** @param list<string> $chunks
     * @return list<list<float>>|null */
    private function embed(array $chunks): ?array
    {
        $token = config('services.ai.token');
        $url = config('services.ai.url');

        if (! is_string($token) || ! is_string($url) || $url === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(30)
                ->acceptJson()
                ->post(rtrim($url, '/').'/v1/embed', ['texts' => $chunks]);

            if ($response->failed()) {
                Log::warning('knowledge_embed_failed', ['status' => $response->status()]);

                return null;
            }

            /** @var list<list<float>> $vectors */
            $vectors = $response->json('vectors');

            return count($vectors) === count($chunks) ? $vectors : null;
        } catch (\Throwable) {
            Log::warning('knowledge_embed_failed', ['reason' => 'transport']);

            return null;
        }
    }

    public static function hasVectorColumn(): bool
    {
        static $cached = null;

        if ($cached === null) {
            $row = DB::connection()->getDriverName() === 'pgsql'
                ? DB::selectOne(
                    "SELECT count(*) AS c FROM information_schema.columns WHERE table_name = 'knowledge_chunks' AND column_name = 'embedding'"
                )
                : null;
            $cached = is_object($row) && property_exists($row, 'c') && (int) $row->c > 0;
        }

        return (bool) $cached;
    }
}
