<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-agent saved inbox views: a named filter set (status/priority/channel/
 * department/search) an agent can re-apply in one click. Private to each user
 * (scoped by user_id in the controller); RLS-scoped to the org like every
 * tenant table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('user_id');
            $table->string('name', 60);
            $table->json('filters');
            $table->timestampTz('created_at')->nullable();
            $table->timestampTz('updated_at')->nullable();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['organization_id', 'user_id']);
        });

        Rls::enable('saved_views');
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
