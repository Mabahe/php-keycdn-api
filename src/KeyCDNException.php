<?php

declare(strict_types=1);

namespace KeyCDN;

/**
 * Thrown when a request could not be completed (transport failure, empty
 * response, invalid options or unencodable parameters).
 *
 * Note: HTTP error statuses (4xx/5xx) with a response body do NOT throw; the
 * KeyCDN API reports errors as JSON in the body, which is returned as usual.
 */
class KeyCDNException extends \RuntimeException
{
}
