<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();
        $driver = $connection->getDriverName();
        $database = $connection->getDatabaseName();
        if (! (($driver === 'sqlite' && $database === ':memory:') || ($driver === 'pgsql' && preg_match('/^familyguard_test(?:_[a-z0-9]+)?$/D', $database)))) {
            throw new \LogicException('Tests require in-memory SQLite or a dedicated familyguard_test database. Refusing destructive test setup.');
        }

        return $app;
    }
}
