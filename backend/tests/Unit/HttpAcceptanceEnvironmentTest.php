<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\HttpAcceptanceEnvironment;

class HttpAcceptanceEnvironmentTest extends TestCase
{
    public function test_only_exact_uncached_loopback_test_environment_is_allowed(): void
    {
        HttpAcceptanceEnvironment::assertAllowed(true, 'pgsql', '127.0.0.1', '5433', 'familyguard_test', false);
        $this->addToAssertionCount(1);
    }

    public function test_development_production_and_similar_database_names_are_rejected(): void
    {
        foreach (['familyguard', 'production', 'familyguard_test_other', ''] as $database) {
            try {
                HttpAcceptanceEnvironment::assertAllowed(true, 'pgsql', '127.0.0.1', '5433', $database, false);
                $this->fail('Unsafe database accepted.');
            } catch (\LogicException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_database_url_override_cannot_redirect_test_database_access(): void
    {
        $this->expectException(\LogicException::class);
        HttpAcceptanceEnvironment::assertAllowed(true, 'pgsql', '127.0.0.1', '5433', 'familyguard_test', false, 'pgsql://remote/production');
    }

    public function test_wrong_host_port_driver_and_cached_or_non_testing_config_are_rejected(): void
    {
        foreach ([[false, 'pgsql', '127.0.0.1', '5433', false], [true, 'pgsql', 'remote', '5433', false],
            [true, 'pgsql', '127.0.0.1', '5432', false], [true, 'sqlite', '127.0.0.1', '5433', false],
            [true, 'pgsql', '127.0.0.1', '5433', true]] as [$testing, $driver, $host, $port, $cached]) {
            try {
                HttpAcceptanceEnvironment::assertAllowed($testing, $driver, $host, $port, 'familyguard_test', $cached);
                $this->fail('Unsafe environment accepted.');
            } catch (\LogicException $expected) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
