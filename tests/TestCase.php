<?php

namespace Tests;

use App\Support\TeamsPersonaConfig;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Database names the suite is allowed to touch.
     *
     * An ALLOW-list, not a deny-list. Naming `soundit_psa` as forbidden would
     * protect exactly one database and silently fail to protect the next one
     * anybody creates. Requiring an affirmative marker means an unrecognised
     * name is refused by default, which is the direction that stays correct as
     * the estate changes.
     */
    private const ALLOWED_TEST_DATABASES = [':memory:'];

    private const ALLOWED_SUFFIXES = ['_test', '_testing'];

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // Deliberately here rather than in setUp(): this runs BEFORE
        // setUpTraits(), which is where RefreshDatabase calls migrate:fresh.
        // Once migrate:fresh has run there is nothing left to protect.
        $this->guardAgainstRunningTestsOnALiveDatabase();
    }

    /**
     * Refuse to run against anything that is not obviously a scratch database.
     *
     * `RefreshDatabase` runs `migrate:fresh`, which DROPS EVERY TABLE. The
     * suite's default target is sqlite `:memory:` and is harmless — but this
     * server has no `pdo_sqlite`, so running the suite here means overriding
     * `DB_DATABASE` by hand, every single time. One mistyped override is the
     * entire distance between a test run and an empty production PSA.
     *
     * The test suite has no legitimate reason to hold a connection to a live
     * database, so this refuses rather than warns — and refuses before a single
     * table is dropped.
     */
    private function guardAgainstRunningTestsOnALiveDatabase(): void
    {
        $name = (string) DB::connection()->getDatabaseName();

        if (in_array($name, self::ALLOWED_TEST_DATABASES, true)) {
            return;
        }

        foreach (self::ALLOWED_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return;
            }
        }

        throw new RuntimeException(
            "REFUSING TO RUN TESTS: the configured database is \"{$name}\", which is not a "
            .'recognised test database. This suite runs migrate:fresh, which drops every table.'
            ."\n\nAllowed: ".implode(', ', self::ALLOWED_TEST_DATABASES)
            .', or any name ending in '.implode(' / ', self::ALLOWED_SUFFIXES)
            ."\n\nOn this server, run the suite with:\n"
            .'  DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_DATABASE=soundit_psa_test php artisan test'
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Reset per-process static memos that RefreshDatabase does NOT clear.
        // TeamsPersonaConfig::enabled() caches in a bare static that otherwise
        // leaks across test methods in the same PHPUnit process — an isolation
        // footgun on the auth-boundary persona registry (a warm memo from one
        // test's writes can bleed into the next test's pre-write assertions).
        // Centralized here so no registry-touching test has to remember it.
        TeamsPersonaConfig::flush();
    }
}
