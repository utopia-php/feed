<?php

declare(strict_types=1);

namespace Utopia\Feed\Exception;

use Utopia\Feed\Exception;

/**
 * Something handed to the library cannot be used: an event with no id, an
 * event id that is not a feed position, an empty feed or consumer name.
 *
 * Always a bug in the caller or in whatever produced the event, never a
 * transient condition — retrying the same input will fail the same way.
 */
class Invalid extends Exception
{
}
