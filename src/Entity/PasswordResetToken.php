<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-use password-reset token for User accounts. The identical fields/lifecycle are shared with
 * the admin token via {@see PasswordResetTokenColumns}; only the table/index/repository are
 * realm-specific. Kept a SEPARATE class and table from AdminPasswordResetToken (ADR-003 / ADR-009).
 */
#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Table(name: 'password_reset_tokens')]
#[ORM\Index(name: 'IDX_PRT_EMAIL', columns: ['email'])]
class PasswordResetToken
{
    use PasswordResetTokenColumns;
}
