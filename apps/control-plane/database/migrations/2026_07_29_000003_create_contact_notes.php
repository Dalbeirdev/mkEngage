<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent notes attached to a CRM contact (not a conversation). Private to the
 * org's agents; RLS-scoped like every tenant table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('contact_id');
            $table->uuid('author_id');
            $table->text('body');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['contact_id', 'created_at']);
        });

        Rls::enable('contact_notes');
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_notes');
    }
};
