<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Denormalized organization_id on Sanctum tokens. This table is auth
     * INFRASTRUCTURE, deliberately NOT RLS-scoped (like sessions/cache/jobs):
     * token lookup happens before tenant context exists — the token is what
     * ESTABLISHES the context (EstablishTenantContext middleware). Lookup is
     * by hashed token value only; the org column lets the middleware set RLS
     * context without a pre-context read of the RLS-protected users table.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->uuid('organization_id')->nullable()->after('tokenable_id');
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropIndex(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
