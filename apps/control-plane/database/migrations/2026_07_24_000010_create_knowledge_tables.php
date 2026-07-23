<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Knowledge base v1 (§10, ADR-003): documents (pasted text/FAQ entries —
     * crawling arrives with the ingestion workers) and their chunks.
     *
     * Hybrid retrieval columns are capability-gated:
     *  - content_tsv (generated tsvector): PostgreSQL only
     *  - embedding vector(1536): only when the pgvector EXTENSION is
     *    installed (CI's pgvector image; managed PG in production). Local
     *    stock PostgreSQL runs the FTS arm alone — retrieval degrades
     *    gracefully, never breaks (§10's "adapter for later migration").
     */
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('title', 200);
            $table->text('body');
            $table->string('status', 12)->default('pending'); // pending|ready|failed
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('document_id');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->string('content_checksum', 64);
            $table->timestampTz('created_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('knowledge_documents')->cascadeOnDelete();
            $table->unique(['document_id', 'chunk_index']);
        });

        Rls::enable('knowledge_documents');
        Rls::enable('knowledge_chunks');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE knowledge_chunks ADD COLUMN content_tsv tsvector GENERATED ALWAYS AS (to_tsvector('english', content)) STORED"
            );
            DB::statement('CREATE INDEX knowledge_chunks_tsv_idx ON knowledge_chunks USING GIN (content_tsv)');

            $vectorAvailable = DB::selectOne(
                "SELECT count(*) AS c FROM pg_extension WHERE extname = 'vector'"
            );

            if (is_object($vectorAvailable) && (int) $vectorAvailable->c > 0) {
                DB::statement('ALTER TABLE knowledge_chunks ADD COLUMN embedding vector(1536)');
                // HNSW per §10; created here because chunk volumes start tiny.
                DB::statement('CREATE INDEX knowledge_chunks_embedding_idx ON knowledge_chunks USING hnsw (embedding vector_cosine_ops)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_documents');
    }
};
