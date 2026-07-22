<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\ApplyTenantContextToTransactions;
use App\Tenancy\TenantContext;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, not singleton: reset per request/job under Octane (ADR-007).
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        // Every outermost BEGIN gets `SET LOCAL app.current_org_id` while a
        // tenant context is established — RLS layer 2 becomes automatic.
        Event::listen(TransactionBeginning::class, ApplyTenantContextToTransactions::class);
    }
}
