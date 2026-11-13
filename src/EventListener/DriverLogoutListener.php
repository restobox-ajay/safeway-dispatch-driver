<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Driver\Api\DriverApiClient;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Driver logout (FEATURE-193): revokes the refresh token at the admin API and deletes this session's
 * tokens, as the Android app's logout does. Only on the driver firewall's dispatcher.
 */
// Priority above Symfony's session-invalidating logout listener: the session still holds the token key here.
#[AsEventListener(event: LogoutEvent::class, dispatcher: 'security.event_dispatcher.driver', priority: 64)]
final readonly class DriverLogoutListener
{
    public function __construct(private DriverApiClient $api) {}

    public function __invoke(LogoutEvent $event): void
    {
        $this->api->logout();
    }
}
