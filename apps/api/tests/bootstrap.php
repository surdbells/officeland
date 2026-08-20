<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test bootstrap — pin the test database before Laravel boots
|--------------------------------------------------------------------------
|
| PHPUnit's <env force="true"> sets $_ENV and calls putenv(), but it does not
| touch $_SERVER. Laravel's environment repository reads $_SERVER FIRST, and
| Docker Compose injects DB_DATABASE=officeland there. The net effect is that
| phpunit.xml appears to select the test database while the suite actually
| connects to the development one — and RefreshDatabase then drops every table
| in it.
|
| That is a destructive failure with no error message, so the override is made
| here, before the framework boots, and asserted permanently by
| Tests\Feature\TestDatabaseIsolationTest.
|
*/

$overrides = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'pgsql',
    'DB_DATABASE' => 'officeland_test',
];

foreach ($overrides as $key => $value) {
    $_SERVER[$key] = $value;
    $_ENV[$key] = $value;
    putenv("{$key}={$value}");
}

require __DIR__.'/../vendor/autoload.php';
