<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when an already-consumed invitation cannot proceed. Two call sites:
 * (1) an atomic claim affects zero rows because a concurrent registration already
 *     consumed the invite — signals the surrounding transaction to roll back so an
 *     invite cannot be used twice (FEATURE-119 / review C27);
 * (2) InvitationService::resend refuses to regenerate a used invitation, which would
 *     otherwise allow a second account (FEATURE-103 AC2 / review C8). Callers map it to
 *     their surface's response (web flash + redirect, API 409 Conflict).
 */
final class InvitationAlreadyUsedException extends \RuntimeException
{
}
