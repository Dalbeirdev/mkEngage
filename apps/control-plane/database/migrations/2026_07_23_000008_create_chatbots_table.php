<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chatbots (§2 chatbot configuration, OpenAPI Chatbot schema). Phase 6
     * scope: identity + prompt + provider routing. Flow definitions
     * (mkEngage Flow, A1) and knowledge bindings arrive with their modules.
     */
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('name', 100);
            $table->string('status', 10)->default('draft'); // draft|active|paused
            $table->text('system_prompt')->nullable();
            $table->string('provider', 20)->default('fake'); // fake|openai|anthropic (ADR-003 routing)
            $table->string('model', 100)->nullable();
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['organization_id', 'status']);
        });

        Rls::enable('chatbots');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->uuid('chatbot_id')->nullable()->after('department_id');
            $table->foreign('chatbot_id')->references('id')->on('chatbots')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropForeign(['chatbot_id']);
            $table->dropColumn('chatbot_id');
        });
        Schema::dropIfExists('chatbots');
    }
};
