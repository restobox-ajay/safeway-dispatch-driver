<?php

declare(strict_types=1);

namespace App\Security\Driver;

use App\Bundle\AuthSecurity\Security\LoginRateLimitListener;
use App\Bundle\AuthSecurity\Security\TooManyLoginAttemptsException;
use App\Service\ConfigService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Driver login throttle (realm `driver` in login_attempts). Dispatch limits logins per IP, but every driver
 * login reaches it from this server, so its limit cannot tell one phone from another (and 20 junk posts would
 * lock every driver out). This app therefore limits each phone's connection, and each login typed, itself:
 * the same knobs as the staff login (rate_limit.max_attempts / rate_limit.window_seconds).
 *
 * Each try is recorded BEFORE the PIN is checked and then counted with itself included, so parallel posts
 * cannot all slip under the limit together. A successful login removes its record (only failures count).
 */
final class DriverLoginThrottle
{
    public const REALM = 'driver';
    private const ATTEMPT_ID = '_driver_login_attempt';

    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigService $config,
        private readonly RequestStack $requests,
    ) {}

    #[AsEventListener(event: CheckPassportEvent::class, priority: 2048)]
    public function onCheckPassport(CheckPassportEvent $event): void
    {
        if (!$event->getAuthenticator() instanceof DriverPinAuthenticator) {
            return;
        }
        $max = $this->config->getInt('rate_limit.max_attempts', LoginRateLimitListener::DEFAULT_MAX_ATTEMPTS);
        if ($max <= 0) {
            return;
        }
        $request = $this->requests->getCurrentRequest();
        if ($request === null) {
            return;
        }
        $ip = $request->getClientIp() ?? '0.0.0.0';
        $login = mb_substr(trim((string) $request->request->get('login', '')), 0, 254);
        $now = new \DateTimeImmutable();
        $cutoff = $now->modify('-' . $this->config->getInt('rate_limit.window_seconds', 300) . ' seconds')->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO login_attempts (ip, email, attempted_at, realm) VALUES (?, ?, ?, ?)',
            [$ip, $login !== '' ? $login : null, $now->format('Y-m-d H:i:s'), self::REALM],
        );
        $id = (int) $this->connection->lastInsertId();

        $byIp = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND ip = ? AND attempted_at > ?',
            [self::REALM, $ip, $cutoff],
        );
        $byLogin = $login === '' ? 0 : (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM login_attempts WHERE realm = ? AND email = ? AND attempted_at > ?',
            [self::REALM, $login, $cutoff],
        );
        if ($byIp > $max || $byLogin > $max) {
            // A refused try is not another failure: it must not push the window further out.
            $this->connection->executeStatement('DELETE FROM login_attempts WHERE id = ?', [$id]);
            throw new TooManyLoginAttemptsException();
        }
        $request->attributes->set(self::ATTEMPT_ID, $id);

        $this->connection->executeStatement(
            'DELETE FROM login_attempts WHERE realm = ? AND attempted_at < ?',
            [self::REALM, $now->modify('-1 day')->format('Y-m-d H:i:s')],
        );
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(self::ATTEMPT_ID);
        if ($event->getAuthenticator() instanceof DriverPinAuthenticator && \is_int($id)) {
            $this->connection->executeStatement('DELETE FROM login_attempts WHERE id = ?', [$id]);
        }
    }
}
