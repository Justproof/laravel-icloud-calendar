<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Justproof\IcloudCalendar\CalDavClient;

function fakeAccount(): void
{
    Http::fake(function (Request $request) {
        return match (true) {
            $request->method() === 'REPORT' => Http::response(caldavFixture('events.xml'), 207),
            $request->url() === 'https://caldav.icloud.com/' => Http::response(caldavFixture('principal.xml'), 207),
            $request->url() === 'https://caldav.icloud.com/123456/principal/' => Http::response(caldavFixture('home.xml'), 207),
            $request->url() === 'https://p45-caldav.icloud.com/123456/calendars/' => Http::response(caldavFixture('calendars.xml'), 207),
            default => Http::response('unexpected '.$request->url(), 500),
        };
    });
}

it('lists calendars', function () {
    fakeAccount();

    $this->artisan('icloud-calendar:calendars')
        ->expectsTable(['Name', 'Color', 'Href'], [
            ['Work', '#1BADF8FF', 'https://p45-caldav.icloud.com/123456/calendars/work-cal-uuid/'],
            ['Home', '#FF2968FF', 'https://p45-caldav.icloud.com/123456/calendars/home-cal-uuid/'],
        ])
        ->assertSuccessful();
});

it('shows busy blocks for the coming days', function () {
    fakeAccount();
    $this->travelTo('2026-11-01 12:00:00');

    $this->artisan('icloud-calendar:busy', ['--days' => 14])
        ->expectsOutputToContain('Dentist')
        ->expectsOutputToContain('busy block(s)')
        ->assertSuccessful();
});

it('fails plainly without credentials', function () {
    config(['icloud-calendar.app_password' => null]);
    $this->app->forgetInstance(CalDavClient::class);

    $this->artisan('icloud-calendar:calendars')
        ->expectsOutputToContain('ICLOUD_CALDAV_APP_PASSWORD')
        ->assertFailed();
});

it('reports a bad password instead of a stack trace', function () {
    Http::fake(['*' => Http::response(caldavFixture('unauthorized.html'), 401)]);

    $this->artisan('icloud-calendar:calendars')
        ->expectsOutputToContain('app-specific password')
        ->assertFailed();
});
