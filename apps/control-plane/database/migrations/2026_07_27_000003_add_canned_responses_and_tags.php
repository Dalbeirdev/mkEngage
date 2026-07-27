<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 25: canned responses (agent productivity) + conversation tags.
 * Tags live as a json array on conversations — organization-lightweight,
 * filterable, no join table needed at this scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('title', 100);
            $table->string('shortcut', 30); // e.g. "greeting" → typed as /greeting
            $table->text('body');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['organization_id', 'shortcut']);
            $table->index('organization_id');
        });

        Rls::enable('canned_responses');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->json('tags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('tags');
        });
    }
};
