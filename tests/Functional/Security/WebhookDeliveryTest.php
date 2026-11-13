<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Bundle\AuthWebhook\Message\SendWebhookMessage;
use App\Bundle\AuthWebhook\MessageHandler\SendWebhookMessageHandler;
use App\Tests\Service\ControlledHttpWebhookDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * FEATURE-104 / ADR-023: webhook delivery moved onto a Messenger worker. The sender
 * (HttpWebhookDispatcher::send) now performs exactly ONE attempt and logs ONE WebhookDelivery
 * row; retries/backoff are Messenger's job. SendWebhookMessageHandler throws on a non-delivered
 * result so Messenger retries and finally dead-letters. These tests exercise that single-attempt
 * sender + the handler's throw-on-failure contract (which drives Messenger's retry_strategy).
 */
final class WebhookDeliveryTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private ControlledHttpWebhookDispatcher $sender;

    private static string $eventType = 'login.success';

    protected function setUp(): void
    {
        static::createClient();
        $this->em     = self::getContainer()->get(EntityManagerInterface::class);
        $this->sender = new ControlledHttpWebhookDispatcher($this->em);

        ControlledHttpWebhookDispatcher::reset();
        $this->cleanupDeliveries();
    }

    protected function tearDown(): void
    {
        $this->cleanupDeliveries();
        parent::tearDown();
    }

    private function cleanupDeliveries(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                'DELETE FROM webhook_delivery WHERE event_type = ?',
                [self::$eventType],
            );
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function makePayload(): array
    {
        return [
            'event_type' => self::$eventType,
            'actor'      => 'delivery-test@example.com',
            'timestamp'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'ip'         => '127.0.0.1',
        ];
    }

    private function countRows(): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM webhook_delivery WHERE event_type = ?',
            [self::$eventType],
        );
    }

    // AC1 (unchanged): webhook_delivery table exists with required columns
    public function testWebhookDeliveryTableHasRequiredColumns(): void
    {
        $conn    = $this->em->getConnection();
        $columns = $conn->fetchAllAssociative('PRAGMA table_info(webhook_delivery)');
        $names   = array_column($columns, 'name');

        $this->assertContains('url', $names, 'webhook_delivery must have a url column');
        $this->assertContains('event_type', $names, 'webhook_delivery must have an event_type column');
        $this->assertContains('payload', $names, 'webhook_delivery must have a payload column');
        $this->assertContains('status', $names, 'webhook_delivery must have a status column');
        $this->assertContains('response_code', $names, 'webhook_delivery must have a response_code column');
        $this->assertContains('attempted_at', $names, 'webhook_delivery must have an attempted_at column');
    }

    // AC5: one send() attempt logs exactly one WebhookDelivery row (no internal retry loop anymore)
    public function testSendPerformsExactlyOneAttemptAndLogsOneRow(): void
    {
        ControlledHttpWebhookDispatcher::queueResponses(500);

        $delivery = $this->sender->send('https://hooks.example.com/test', $this->makePayload());

        $this->assertSame(1, $this->countRows(), 'send() must log exactly one row per attempt — no internal retries');
        $this->assertSame('failed', $delivery->getStatus());
        $this->assertSame(500, $delivery->getResponseCode());
    }

    // AC4: a non-delivered attempt makes the handler throw, so Messenger retries and eventually
    // dead-letters. The retry itself is Messenger's retry_strategy, not an app loop.
    public function testHandlerThrowsOnFailedDeliveryToTriggerMessengerRetry(): void
    {
        ControlledHttpWebhookDispatcher::queueResponses(503);
        $handler = new SendWebhookMessageHandler($this->sender);

        $this->expectException(\RuntimeException::class);
        try {
            $handler(new SendWebhookMessage('https://hooks.example.com/test', $this->makePayload()));
        } finally {
            // The attempt is still logged before the throw (delivery audit preserved).
            $this->assertSame(1, $this->countRows(), 'A failed attempt must still log one delivery row');
        }
    }

    // AC5: a 2xx delivery is logged 'delivered' and the handler does NOT throw (no retry).
    public function testHandlerSucceedsAndLogsDeliveredOnSuccess(): void
    {
        ControlledHttpWebhookDispatcher::queueResponses(201);
        $handler = new SendWebhookMessageHandler($this->sender);

        $handler(new SendWebhookMessage('https://hooks.example.com/test', $this->makePayload()));

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT status, response_code FROM webhook_delivery WHERE event_type = ? ORDER BY id ASC',
            [self::$eventType],
        );
        $this->assertCount(1, $rows, 'A successful delivery logs exactly one row');
        $this->assertSame('delivered', $rows[0]['status']);
        $this->assertSame(201, (int) $rows[0]['response_code']);
    }
}
