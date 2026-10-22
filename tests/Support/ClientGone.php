<?php

declare(strict_types=1);

namespace Lossik\Mikrotik\Api\Tests\Support;

/**
 * Inside the fake router: the client disconnected (or stayed silent too long), the script ends.
 */
final class ClientGone extends \RuntimeException
{
}
