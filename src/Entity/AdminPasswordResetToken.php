<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AdminPasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Password-reset token for Admin accounts. Kept in a SEPARATE table from the user
 * password_reset_tokens so a user and an admin that happen to share an email address can
 * never consume each other's tokens (ADR-003 / ADR-009). The identical fields/lifecycle are shared
 * with the user token via {@see PasswordResetTokenColumns}; only the table/index/repository differ.
 */
#[ORM\Entity(repositoryClass: AdminPasswordResetTokenRepository::class)]
#[ORM\Table(name: 'admin_password_reset_tokens')]
#[ORM\Index(name: 'IDX_APRT_EMAIL', columns: ['email'])]
class AdminPasswordResetToken
{
    use PasswordResetTokenColumns;
}
