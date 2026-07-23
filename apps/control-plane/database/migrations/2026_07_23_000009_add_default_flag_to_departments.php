<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Routing v1 (ASSUMPTIONS A5): new widget conversations land in the
     * organization's DEFAULT department. Single-default is enforced in the
     * controller (same pattern as the single-active chatbot); rule-based
     * routing (page URL, office hours, load balancing) is a later module.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn('is_default');
        });
    }
};
