<?php

use Justproof\IcloudCalendar\Tests\TestCase;

pest()->extend(TestCase::class)->in(__DIR__);

function caldavFixture(string $name): string
{
    return TestCase::fixture($name);
}
