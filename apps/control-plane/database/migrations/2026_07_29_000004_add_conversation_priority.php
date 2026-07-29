<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket priority on conversations (low|normal|high|urgent). Defaults to
 * normal so existing rows and every new conversation start triaged; agents
 * raise/lower it. Indexed for the inbox priority filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('priority', 10)->default('normal'); // low|normal|high|urgent
            $table->index(['organization_id', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'priority']);
            $table->dropColumn('priority');
        });
    }
};
