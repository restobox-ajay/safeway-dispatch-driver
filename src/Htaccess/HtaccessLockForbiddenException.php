<?php

declare(strict_types=1);

namespace App\Htaccess;

/** Thrown by the gate when the caller is not tech-support (or the console). Adapters map it to 403. */
final class HtaccessLockForbiddenException extends \RuntimeException
{
}
