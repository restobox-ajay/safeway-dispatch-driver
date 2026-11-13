<?php

declare(strict_types=1);

namespace App\Security\Driver;

use App\Driver\Api\DriverTokenStore;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The /driver firewall's users come from this browser session's driver_api_session row. When the row is
 * gone (logout, or the admin API refused to renew the tokens) the driver is signed out on the next request.
 *
 * @implements UserProviderInterface<DriverUser>
 */
final class DriverUserProvider implements UserProviderInterface
{
    public function __construct(private readonly DriverTokenStore $tokens) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $row = $this->tokens->current();
        if ($row === null || DriverUser::identifierFor($row->getDriverId()) !== $identifier) {
            throw new UserNotFoundException();
        }

        return new DriverUser($row->getDriver());
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof DriverUser) {
            throw new UnsupportedUserException(sprintf('Unsupported user "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === DriverUser::class;
    }
}
