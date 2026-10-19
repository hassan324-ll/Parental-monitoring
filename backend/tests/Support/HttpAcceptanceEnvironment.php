<?php

namespace Tests\Support;

final class HttpAcceptanceEnvironment
{
    public static function assertAllowed(bool $testing, string $driver, string $host, string $port, string $database, bool $cached, string $url = ''): void
    {
        if (! $testing || $cached || $url !== '' || $driver !== 'pgsql' || $host !== '127.0.0.1' || $port !== '5433' || $database !== 'familyguard_test') {
            throw new \LogicException('HTTP acceptance requires uncached testing config and loopback familyguard_test PostgreSQL on 5433.');
        }
    }

    public static function guard($app): void
    {
        $config = $app['config']->get('database.connections.pgsql');
        self::assertAllowed($app->environment('testing'), $app['config']->get('database.default'),
            (string) $config['host'], (string) $config['port'], (string) $config['database'], $app->configurationIsCached(), (string) ($config['url'] ?? ''));
    }
}
