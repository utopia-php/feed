<?php

declare(strict_types=1);

namespace Utopia\Feed\Exception;

use Utopia\Feed\Exception;

// Something handed to the library cannot be used. Always a bug in the caller
class Invalid extends Exception
{
}
