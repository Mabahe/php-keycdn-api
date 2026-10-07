<?php

declare(strict_types=1);

namespace KeyCDN\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;

final class TransportFailure extends \RuntimeException implements ClientExceptionInterface
{
}
