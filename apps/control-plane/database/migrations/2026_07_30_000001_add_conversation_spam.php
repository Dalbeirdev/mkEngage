<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spam flag on conversations. Marked-spam threads drop out of the default
 * inbox and only surface in the Spam view — junk/abuse triage for the
 * ticketing surface. Indexed for the default "not spam" filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->boolean('is_spam')->default(false);
            $table->index(['organization_id', 'is_spam']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'is_spam']);
            $table->dropColumn('is_spam');
        });
    }
};
