<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (getenv('DB_CONNECTION') === 'pgsql' && $app['config']->get('database.default') !== 'pgsql') {
            throw new \RuntimeException('PostgreSQL test configuration was not applied.');
        }
        if ($app['config']->get('database.default') === 'pgsql'
            && !preg_match('/^stripe_demo_test(?:_[a-z0-9]+)?$/', (string) $app['db']->connection()->getDatabaseName())) {
            throw new \RuntimeException('PostgreSQL feature tests require a disposable stripe_demo_test database.');
        }
        return $app;
    }
}
