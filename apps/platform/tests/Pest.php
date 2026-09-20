<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Totp;
use Tests\TestCase;

// Feature tests boot the application and run against the real test database
// (MariaDB by default; PostgreSQL via `./flow test backend --pgsql`). Migrating
// on every run is what keeps both engines honest.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->in('Unit');

// Concurrency tests COMMIT real data and run a second PHP process against it, so they cannot sit
// inside RefreshDatabase's wrapping transaction. They clean up after themselves.
pest()->extend(TestCase::class)->in('Concurrency');

// Live tests reach a REAL external service, so they are in no test suite (phpunit.xml) and never run
// as part of ./flow test, ./flow check, CI or e2e. See tests/Live.
pest()->extend(TestCase::class)->in('Live');

// The TOTP test double remembers which time steps it has handed out (the platform accepts each once).
pest()->beforeEach(fn () => Totp::forget())->in('Feature', 'Concurrency');
