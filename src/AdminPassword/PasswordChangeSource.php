<?php

declare(strict_types=1);

namespace App\AdminPassword;

/** Which of the three ways an admin's password can change is being used; decides how the gate authorises it. */
enum PasswordChangeSource: string
{
    /** The holder of a valid emailed reset link. */
    case ResetLink = 'reset_link';

    /** The logged-in admin changing their own password (proves the current one). */
    case Own = 'own';

    /** A tech-support admin setting another admin's password. */
    case Support = 'support';
}
