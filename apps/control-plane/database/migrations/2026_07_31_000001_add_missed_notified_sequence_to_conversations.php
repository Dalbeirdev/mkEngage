<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Missed-conversation email notifications: remembers the highest message
// sequence agents were already notified about, so each waiting inbound
// message triggers at most one email.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->unsignedInteger('missed_notified_sequence')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('missed_notified_sequence');
        });
    }
};
