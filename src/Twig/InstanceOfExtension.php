<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigTest;

/**
 * Adds an `is instanceof('Fully\\Qualified\\Class')` Twig test so templates can branch on
 * the concrete entity type rather than on role strings. Used by the layout to pick the
 * admin vs user sidebar from the authenticated *entity* (Admin vs User) — the security
 * boundary — instead of is_granted('ROLE_ADMIN'), which is a role and not an identity.
 */
final class InstanceOfExtension extends AbstractExtension
{
    public function getTests(): array
    {
        return [
            new TwigTest('instanceof', static fn ($value, string $class): bool => $value instanceof $class),
        ];
    }
}
