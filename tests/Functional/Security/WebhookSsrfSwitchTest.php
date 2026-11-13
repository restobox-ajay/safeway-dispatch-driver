<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Bundle\AuthWebhook\Security\SsrfGuard;
use App\Bundle\AuthWebhook\Service\HttpWebhookDispatcher;
use App\Service\ConfigService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-105 / ADR-027, AC8 + AC10: the SSRF guard is governed by the DB-backed
 * `webhook.block_internal_targets` switch, which defaults ON (fail-closed). This exercises the
 * real (autowired) SsrfGuard + ConfigService against the DB, and the real HttpWebhookDispatcher's
 * blocked-delivery path (a loopback target under the default switch is not delivered).
 */
final class WebhookSsrfSwitchTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private ConfigService $config;
    private SsrfGuard $guard;

    private const INTERNAL_URL = 'http://127.0.0.1/hook';

    protected function setUp(): void
    {
        static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->config = self::getContainer()->get(ConfigService::class);
        $this->guard  = self::getContainer()->get(SsrfGuard::class);

        $this->clearSwitch();
        $this->clearDeliveries();
    }

    protected function tearDown(): void
    {
        $this->clearSwitch();
        $this->clearDeliveries();
        parent::tearDown();
    }

    private function clearSwitch(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM config WHERE config_key = ?',
                [SsrfGuard::CONFIG_KEY],
            );
        } catch (\Throwable) {
        }
    }

    private function clearDeliveries(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                "DELETE FROM webhook_delivery WHERE event_type = 'ssrf.switch.test'",
            );
        } catch (\Throwable) {
        }
    }

    // AC8: unconfigured => ON (fail-closed). A loopback target is refused by the guard decision.
    public function testDefaultSwitchIsOnAndBlocksInternalTarget(): void
    {
        $this->assertTrue($this->guard->isEnabled(), 'switch defaults ON when unconfigured');
        $this->assertFalse(
            $this->guard->inspect(self::INTERNAL_URL)->allowed,
            'internal/loopback target is refused under the default switch',
        );
    }

    // AC10: with the switch explicitly OFF, the same internal target is allowed.
    public function testSwitchOffAllowsInternalTarget(): void
    {
        $this->config->set(SsrfGuard::CONFIG_KEY, '0');

        $this->assertFalse($this->guard->isEnabled(), 'switch reads OFF from the DB');
        $this->assertTrue(
            $this->guard->inspect(self::INTERNAL_URL)->allowed,
            'internal target is allowed when the operator opts out',
        );
    }

    // AC10 end-to-end: the real dispatcher does not deliver to a blocked target under the default
    // switch — it logs a failed delivery with no response code and makes no outbound call.
    public function testRealDispatcherDoesNotDeliverToBlockedTarget(): void
    {
        /** @var HttpWebhookDispatcher $dispatcher */
        $dispatcher = self::getContainer()->get(HttpWebhookDispatcher::class);

        $delivery = $dispatcher->send(self::INTERNAL_URL, [
            'event_type' => 'ssrf.switch.test',
            'actor'      => 'ssrf-test@example.com',
        ]);

        $this->assertSame('failed', $delivery->getStatus(), 'blocked target must not be delivered');
        $this->assertNull($delivery->getResponseCode(), 'no HTTP call was made, so no response code');
    }
}
