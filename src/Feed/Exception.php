<?php

declare(strict_types=1);

namespace Utopia\Feed;

/**
 * Base class for every error this library raises, so a caller can catch all of
 * them without also catching unrelated failures from the backend it happens to
 * be sitting on.
 */
class Exception extends \Exception
{
}
