<?php

declare(strict_types=1);

namespace App\Htaccess;

enum HtaccessLockOutcome: string
{
    /** The change was validated, written to .htaccess and recorded. */
    case Applied = 'applied';
    /** Input failed validation, or the self-lockout guard refused it. Nothing was written. */
    case Rejected = 'rejected';
    /** The entry to add is already present. Nothing was written. */
    case Conflict = 'conflict';
    /** The entry to remove is not present. Nothing was written. */
    case NotFound = 'not_found';
    /** Validation passed but .htaccess could not be written. */
    case WriteFailed = 'write_failed';
}
