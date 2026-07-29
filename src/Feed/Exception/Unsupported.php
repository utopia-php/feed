<?php

declare(strict_types=1);

namespace Utopia\Feed\Exception;

use Utopia\Feed\Exception;

/**
 * The adapter cannot do what was asked of it — appending to a feed read over
 * HTTP, or any operation at all on {@see \Utopia\Feed\Adapter\None}.
 *
 * Thrown rather than ignored: an append that silently does nothing loses
 * events, and a consumer cannot tell an empty feed from an absent one.
 */
class Unsupported extends Exception
{
}
