<?php

declare(strict_types=1);

namespace App\Bundle\AuthWebhook\EventListener;

use App\EventListener\InteractiveFirewallTrait;
use App\Service\ConfigService;
use App\Service\WebhookDispatcherInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Fires login.success / login.failure webhooks. Owned by auth-webhook-bundle (FEATURE-141), so with the
 * bundle absent this listener does not exist and login events emit no webhook. It reuses the CORE
 * {@see InteractiveFirewallTrait} (shared by other core listeners) so a stateless bearer-token (PAT)
 * auth on the `api` firewall never triggers a webhook (review C2 / FEATURE-097).
 */
#[AsEventListener(event: LoginSuccessEvent::class, method: 'onLoginSuccess')]
#[AsEventListener(event: LoginFailureEvent::class, method: 'onLoginFailure')]
class WebhookListener
{
    use InteractiveFirewallTrait;

    public function __construct(
        private WebhookDispatcherInterface $webhookDispatcher,
        private ConfigService $configService,
    ) {}

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $url = $this->resolveUrl('webhook.login_url');
        if ($url === '') {
            return;
        }

        $user    = $event->getUser();
        $request = $event->getRequest();

        $this->webhookDispatcher->dispatch($url, [
            'event_type' => 'login.success',
            'actor'      => $user->getUserIdentifier(),
            'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'ip'         => $request->getClientIp() ?? '0.0.0.0',
        ]);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (!$this->isInteractiveFirewall($event->getFirewallName())) {
            return;
        }

        $url = $this->resolveUrl('webhook.login_url');
        if ($url === '') {
            return;
        }

        $request = $event->getRequest();

        $this->webhookDispatcher->dispatch($url, [
            'event_type' => 'login.failure',
            // The posted email is attacker-controlled and unbounded; it is stored in webhook_delivery and sent to
            // the receiver, so it gets the same 255-char cap as the audit log's actor (issue #22).
            'actor'      => mb_substr(trim((string) $request->request->get('email', 'unknown')), 0, 255) ?: 'unknown',
            'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'ip'         => $request->getClientIp() ?? '0.0.0.0',
        ]);
    }

    private function resolveUrl(string $perEventKey): string
    {
        $perEvent = $this->configService->getString($perEventKey, '');
        if ($perEvent !== '') {
            return $perEvent;
        }

        return $this->configService->getString('webhook.global_url', '');
    }
}
