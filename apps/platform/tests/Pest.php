<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Feature tests boot the application and run against the real test database
// (MariaDB by default; PostgreSQL via `./flow test backend --pgsql`). Migrating
// on every run is what keeps both engines honest.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

pest()->extend(TestCase::class)->in('Unit');
