<?php

declare(strict_types=1);

namespace App\Security\Driver;

use App\Driver\Api\DriverTokenStore;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * The signed-in driver, rebuilt on every request from the driver object kept with this session's API tokens
 * (DriverTokenStore). A session without tokens has no driver.
 *
 * @implements UserProviderInterface<DriverUser>
 */
final class DriverUserProvider implements UserProviderInterface
{
    public function __construct(private readonly DriverTokenStore $tokens) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $tokens = $this->tokens->current();
        if ($tokens === null || DriverUser::identifierFor($tokens->getDriverId()) !== $identifier) {
            throw new UserNotFoundException();
        }

        return new DriverUser($tokens->getDriver());
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
