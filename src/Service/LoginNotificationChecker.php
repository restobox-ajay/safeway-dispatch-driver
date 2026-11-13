<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

final class LoginNotificationChecker
{
    public function __construct(
        private readonly ConfigService $configService,
    ) {
    }

    public function shouldNotifyUser(User $user): bool
    {
        if (!$this->configService->getBool('login_notifications.enabled', true)) {
            return false;
        }

        return $user->isLoginNotificationsEnabled();
    }
}
