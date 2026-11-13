<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown inside the personal-access-token creation transaction when the fresh,
 * post-insert active-token count exceeds the configured per-user cap. Signals the
 * surrounding transaction to roll back so a concurrent create cannot exceed the cap
 * (FEATURE-119 / review C27).
 */
final class TokenLimitExceededException extends \RuntimeException
{
}
