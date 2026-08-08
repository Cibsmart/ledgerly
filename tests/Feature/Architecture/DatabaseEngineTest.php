<?php

use Illuminate\Support\Facades\DB;

/**
 * Guardrail: the PostgreSQL CI job must actually be running on PostgreSQL.
 *
 * phpunit.xml pins DB_CONNECTION to sqlite so the local suite stays fast. The
 * `tests-pgsql` workflow job overrides that with a real environment variable,
 * which works only because PHPUnit skips an <env> entry whose variable is
 * already set. That is a quiet dependency: add force="true" to phpunit.xml, or
 * mistype a job-level variable, and the job degrades into a second sqlite run
 * that reports green while testing nothing sqlite could not already test.
 *
 * EXPECTED_DB_DRIVER is set only by that job, and is deliberately read straight
 * from the environment rather than from config — asserting that config matches
 * config would pass in exactly the case this exists to catch.
 */
test('the suite runs on the database engine CI selected', function () {
    $expected = env('EXPECTED_DB_DRIVER');

    expect(DB::connection()->getDriverName())->toBe($expected);
})->skip(
    fn (): bool => blank(env('EXPECTED_DB_DRIVER')),
    'EXPECTED_DB_DRIVER is only set by the PostgreSQL CI job.'
);
