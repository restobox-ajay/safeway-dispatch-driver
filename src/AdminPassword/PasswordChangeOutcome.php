<?php

declare(strict_types=1);

namespace App\AdminPassword;

enum PasswordChangeOutcome
{
    /** The password was changed. */
    case Changed;

    /** The input was refused and can be fixed and resubmitted (policy, mismatch, wrong current password). */
    case Rejected;

    /** The link/target cannot be used at all (expired or used link, inactive or missing account). */
    case Invalid;

    /** The caller is not allowed to change this password. */
    case Forbidden;
}
