<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coarse visitor geolocation, captured from the edge proxy's geo headers at
 * session bootstrap (no third-party lookups, no stored IP). country_code is
 * ISO-3166 alpha-2; city is only present when the proxy provides it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->char('country_code', 2)->nullable();
            $table->string('city', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropColumn(['country_code', 'city']);
        });
    }
};
