<?php

namespace Justproof\IcloudCalendar\Tests;

use Justproof\IcloudCalendar\IcloudCalendarServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [IcloudCalendarServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('icloud-calendar', [
            'base_url' => 'https://caldav.icloud.com',
            'username' => 'owner@icloud.com',
            'app_password' => 'abcd-efgh-ijkl-mnop',
            'timeout' => 5,
            'user_agent' => 'Laravel iCloud Calendar/test',
            'timezone' => 'America/Chicago',
            'busy_cache_seconds' => 300,
            'cache_prefix' => 'icloud-calendar',
            'owner_emails' => [],
        ]);
    }

    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__."/fixtures/caldav/{$name}");
    }
}
