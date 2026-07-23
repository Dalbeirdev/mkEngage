<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\IngestKnowledgeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Hybrid retrieval (§10): PostgreSQL FTS + pgvector cosine, merged with
 * Reciprocal Rank Fusion. Each arm is capability-gated — FTS requires
 * PostgreSQL; the vector arm additionally requires the pgvector column and
 * a reachable embedder. Runs under the caller's tenant context (RLS scopes
 * every query; ADR-007).
 */
final class KnowledgeRetriever
{
    private const ARM_LIMIT = 10;

    private const RRF_K = 60;

    /** @return list<array{content: string, document_title: string}> */
    public function retrieve(string $query, int $limit = 3): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || trim($query) === '') {
            return [];
        }

        $ranks = [];

        foreach ($this->ftsArm($query) as $rank => $chunkId) {
            $ranks[$chunkId] = ($ranks[$chunkId] ?? 0) + 1 / (self::RRF_K + $rank + 1);
        }

        foreach ($this->vectorArm($query) as $rank => $chunkId) {
            $ranks[$chunkId] = ($ranks[$chunkId] ?? 0) + 1 / (self::RRF_K + $rank + 1);
        }

        if ($ranks === []) {
            return [];
        }

        arsort($ranks);
        $topIds = array_slice(array_keys($ranks), 0, $limit);

        $rows = DB::table('knowledge_chunks')
            ->join('knowledge_documents', 'knowledge_documents.id', '=', 'knowledge_chunks.document_id')
            ->whereIn('knowledge_chunks.id', $topIds)
            ->where('knowledge_documents.status', 'ready')
            ->get(['knowledge_chunks.id', 'knowledge_chunks.content', 'knowledge_documents.title']);

        $byId = $rows->keyBy('id');

        $results = [];
        foreach ($topIds as $chunkId) {
            $row = $byId->get($chunkId);
            if ($row !== null) {
                $results[] = [
                    'content' => (string) $row->content,
                    'document_title' => (string) $row->title,
                ];
            }
        }

        return $results;
    }

    /** @return list<string> chunk ids, best first */
    private function ftsArm(string $query): array
    {
        // OR-semantics: plainto_tsquery ANDs every term, so one absent word
        // (e.g. "long" in "how long does shipping take") kills the match.
        // Retrieval wants ANY-term matching ranked by density.
        $terms = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
        $terms = array_slice(array_values(array_filter($terms, fn ($t) => mb_strlen($t) > 1)), 0, 8);

        if ($terms === []) {
            return [];
        }

        $orQuery = implode(' OR ', $terms);

        $rows = DB::select(
            'SELECT kc.id FROM knowledge_chunks kc
             JOIN knowledge_documents kd ON kd.id = kc.document_id
             WHERE kd.status = ? AND kc.content_tsv @@ websearch_to_tsquery(\'english\', ?)
             ORDER BY ts_rank(kc.content_tsv, websearch_to_tsquery(\'english\', ?)) DESC
             LIMIT '.self::ARM_LIMIT,
            ['ready', $orQuery, $orQuery],
        );

        return array_values(array_map(fn ($row): string => (string) $row->id, $rows));
    }

    /** @return list<string> chunk ids, best first */
    private function vectorArm(string $query): array
    {
        if (! IngestKnowledgeDocument::hasVectorColumn()) {
            return [];
        }

        $vector = $this->embedQuery($query);

        if ($vector === null) {
            return [];
        }

        $literal = '['.implode(',', $vector).']';

        $rows = DB::select(
            'SELECT kc.id FROM knowledge_chunks kc
             JOIN knowledge_documents kd ON kd.id = kc.document_id
             WHERE kd.status = ? AND kc.embedding IS NOT NULL
             ORDER BY kc.embedding <=> ?::vector
             LIMIT '.self::ARM_LIMIT,
            ['ready', $literal],
        );

        return array_values(array_map(fn ($row): string => (string) $row->id, $rows));
    }

    /** @return list<float>|null */
    private function embedQuery(string $query): ?array
    {
        $token = config('services.ai.token');
        $url = config('services.ai.url');

        if (! is_string($token) || ! is_string($url) || $url === '') {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->acceptJson()
                ->post(rtrim($url, '/').'/v1/embed', ['texts' => [$query]]);

            if ($response->failed()) {
                return null;
            }

            /** @var list<list<float>> $vectors */
            $vectors = $response->json('vectors');

            return $vectors[0] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
