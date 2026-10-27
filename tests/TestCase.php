<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** RefreshDatabase wipes the database: never run against anything but the test database. */
    private const TEST_DATABASE = 'scms_test';

    /** Runs after the app is booted and before any trait (RefreshDatabase included) touches the database. */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');
        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException("Tests must run on the '".self::TEST_DATABASE."' database, not '{$database}'. Check phpunit.xml.");
        }

        return parent::setUpTraits();
    }
}
