<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat attachments (§14): rows carry metadata + tenant-scoped storage
     * paths; bytes live on the object-storage disk (S3-compatible in prod,
     * local in dev — never a database column). Quarantine model: files are
     * born `pending`, only `clean` files are downloadable; `quarantined`
     * files stay on disk for forensics but are never served.
     */
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('conversation_id');
            $table->uuid('message_id')->nullable(); // linked at send time
            $table->string('uploader_type', 20); // visitor | user
            $table->uuid('uploader_id');
            $table->string('file_name', 255); // sanitized original name
            $table->string('content_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->string('storage_path', 500); // tenant-specific (§14)
            $table->string('scan_status', 20)->default('pending'); // pending | clean | quarantined
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('messages')->nullOnDelete();
            $table->index('conversation_id');
            $table->index('message_id');
        });

        Rls::enable('attachments');
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
