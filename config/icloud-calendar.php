<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Account
    |--------------------------------------------------------------------------
    |
    | The Apple ID (its primary email, not an alias) and an app-specific
    | password minted at account.apple.com. Never the account password.
    | iCloud answers discovery on caldav.icloud.com and then hands you a
    | pNN-caldav.icloud.com host for everything else; the client follows it.
    |
    */

    'base_url' => env('ICLOUD_CALDAV_BASE_URL', 'https://caldav.icloud.com'),

    'username' => env('ICLOUD_CALDAV_USERNAME'),

    'app_password' => env('ICLOUD_CALDAV_APP_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('ICLOUD_CALDAV_TIMEOUT', 5),

    'user_agent' => env('ICLOUD_CALDAV_USER_AGENT', 'Laravel iCloud Calendar/1.0'),

    /*
    |--------------------------------------------------------------------------
    | Time
    |--------------------------------------------------------------------------
    |
    | All-day events have no timezone of their own, so they are pinned to the
    | calendar owner's timezone before they become busy blocks. Busy blocks
    | are always returned in UTC.
    |
    */

    'timezone' => env('ICLOUD_CALDAV_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Busy time cache
    |--------------------------------------------------------------------------
    |
    | BusyTimeCache keeps each (calendar set, UTC day range) for this many
    | seconds under a generation key, so one bump() forgets every range
    | without Cache::tags(). The file and database stores are fine.
    |
    */

    'busy_cache_seconds' => (int) env('ICLOUD_CALDAV_BUSY_CACHE_SECONDS', 300),

    'cache_prefix' => 'icloud-calendar',

    /*
    |--------------------------------------------------------------------------
    | Owner addresses
    |--------------------------------------------------------------------------
    |
    | Invitations the owner declined still exist as events on the calendar.
    | Any ATTENDEE with PARTSTAT=DECLINED whose address is in this list makes
    | the event transparent. Leave empty to use the username only.
    |
    */

    'owner_emails' => array_filter(explode(',', (string) env('ICLOUD_CALDAV_OWNER_EMAILS', ''))),

];
