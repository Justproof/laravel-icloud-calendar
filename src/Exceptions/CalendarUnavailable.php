<?php

namespace Justproof\IcloudCalendar\Exceptions;

use RuntimeException;
use Throwable;

/**
 * iCloud could not be reached or refused us. Thrown, never swallowed into an
 * empty busy list: an empty list means "free all day", and a timeout does not.
 */
class CalendarUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }
}
