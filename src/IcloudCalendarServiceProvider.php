<?php

namespace Justproof\IcloudCalendar;

use Illuminate\Support\ServiceProvider;
use Justproof\IcloudCalendar\Console\ListCalendarsCommand;
use Justproof\IcloudCalendar\Console\ShowBusyCommand;

class IcloudCalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/icloud-calendar.php', 'icloud-calendar');

        $this->app->singleton(CalDavClient::class, fn ($app) => new CalDavClient($app['config']->get('icloud-calendar', [])));

        $this->app->singleton(BusyTimeCache::class, fn ($app) => new BusyTimeCache(
            $app->make(CalDavClient::class),
            $app['config']->get('icloud-calendar', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/icloud-calendar.php' => config_path('icloud-calendar.php'),
            ], 'icloud-calendar-config');

            $this->commands([
                ListCalendarsCommand::class,
                ShowBusyCommand::class,
            ]);
        }
    }
}
