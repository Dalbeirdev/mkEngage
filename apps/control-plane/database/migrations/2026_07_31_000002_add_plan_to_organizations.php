<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Billing v1: plan tier + expiry on the tenancy root. Entitlements per plan
// live in config/plans.php; activation is operator-driven (org:plan).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('plan', 20)->default('free');
            $table->timestamp('plan_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['plan', 'plan_expires_at']);
        });
    }
};
