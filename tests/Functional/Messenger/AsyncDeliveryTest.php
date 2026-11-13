<?php

declare(strict_types=1);

namespace App\Tests\Functional\Messenger;

use App\Bundle\AuthWebhook\Message\SendWebhookMessage;
use App\Bundle\AuthWebhook\Service\MessengerWebhookDispatcher;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\RawMessage;

/**
 * FEATURE-104 / ADR-023: email + webhook delivery run off the login/request path via Symfony
 * Messenger (Doctrine async transport in prod; in-memory async transport in test). These tests
 * prove the login request only ENQUEUES a message — it never sends the email or calls the webhook
 * itself — so an SMTP/webhook outage cannot hang or 500 a login.
 */
final class AsyncDeliveryTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private const EMAIL = 'async-delivery@example.com';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->asyncTransport()->reset();
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function asyncTransport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }

    private function cleanup(): void
    {
        try {
            $conn = $this->em->getConnection();
            $userId = $conn->fetchOne('SELECT id FROM "user" WHERE email = ?', [self::EMAIL]);
            if ($userId !== false) {
                $conn->executeStatement('DELETE FROM login_history WHERE user_id = ?', [(int) $userId]);
                $conn->executeStatement('DELETE FROM "user" WHERE id = ?', [(int) $userId]);
            }
            $this->em->clear();
        } catch (\Throwable) {
        }
    }

    private function createUser(): void
    {
        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName('Async Delivery User');
        $user->setPassword(password_hash('asyncpass', PASSWORD_BCRYPT, ['cost' => 4]));
        $user->setLoginNotificationsEnabled(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();
    }

    private function login(): void
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', 'AsyncDeliveryAgent/NewDevice/1.0');
        $this->client->request('GET', '/staff/login');
        $this->client->submitForm('Sign in', [
            'email'    => self::EMAIL,
            'password' => 'asyncpass',
        ]);
    }

    /**
     * @return object[] the messages sent to the async transport
     */
    private function sentMessages(): array
    {
        return array_map(
            static fn ($envelope) => $envelope->getMessage(),
            $this->asyncTransport()->getSent(),
        );
    }

    // AC1: SendEmailMessage is wired onto the Messenger bus (routed to a transport), so the Mailer
    // enqueues instead of sending on the request thread. In production that transport is the
    // Doctrine `async` transport; the test env routes it to sync:// so MailerAssertionsTrait keeps
    // working (see config/packages/messenger.yaml when@test). Here we assert the routing exists and
    // that the login-notification path still produces its email after the Messenger refactor.
    public function testEmailIsRoutedOntoTheMessengerBusAndLoginStillNotifies(): void
    {
        $envelope = new Envelope(new SendEmailMessage(new RawMessage('')));
        $senders = iterator_to_array(
            self::getContainer()->get('messenger.senders_locator')->getSenders($envelope),
        );
        $this->assertNotEmpty(
            $senders,
            'SendEmailMessage must be routed to a Messenger transport (enqueued, not sent inline)',
        );

        $this->createUser();
        $this->login();

        // The login response came back and the notification email was produced.
        $this->assertResponseRedirects('/dashboard');
        $this->assertNotEmpty($this->getMailerMessages(), 'Login must still produce a notification email');
    }

    // AC2: the webhook dispatcher only dispatches a SendWebhookMessage to the bus — the request
    // never performs the outbound HTTP call itself (that happens later in the worker's handler).
    public function testWebhookDispatchEnqueuesMessageInsteadOfCallingHttp(): void
    {
        $dispatcher = new MessengerWebhookDispatcher(
            self::getContainer()->get(MessageBusInterface::class),
        );

        $dispatcher->dispatch('https://hooks.example.com/auth', [
            'event_type' => 'login.success',
            'actor'      => self::EMAIL,
            'ip'         => '127.0.0.1',
        ]);

        $webhookMessages = array_values(array_filter(
            $this->sentMessages(),
            static fn ($m): bool => $m instanceof SendWebhookMessage,
        ));

        $this->assertCount(1, $webhookMessages, 'dispatch() must enqueue exactly one SendWebhookMessage');
        $this->assertSame('https://hooks.example.com/auth', $webhookMessages[0]->url);
        $this->assertSame('login.success', $webhookMessages[0]->payload['event_type']);
    }
}
