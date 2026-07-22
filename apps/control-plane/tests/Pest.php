<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * True when the test run is wired to PostgreSQL (the RLS suite requires it;
 * it skips loudly on SQLite — RLS is a PostgreSQL feature, ADR-007).
 */
function runningOnPostgres(): bool
{
    return config('database.default') === 'pgsql';
}
