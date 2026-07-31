<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stripe checkout: link an organization to its Stripe customer +
// subscription so webhook events can be attributed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('stripe_customer_id', 64)->nullable();
            $table->string('stripe_subscription_id', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['stripe_customer_id', 'stripe_subscription_id']);
        });
    }
};
