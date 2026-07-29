<?php

declare(strict_types=1);

namespace Utopia\Feed\Exception;

use Utopia\Feed\Exception;

/**
 * The backend could not be reached, or rejected the operation.
 *
 * Usually transient, so a consumer should leave its cursor where it is and try
 * again rather than skipping past the events it failed to read.
 *
 * For feeds read over HTTP the code is the response status, which is how a
 * caller distinguishes a producer that does not serve the feed yet (404,
 * expected while a rollout is in progress) from one that is broken.
 */
class Transport extends Exception
{
}
