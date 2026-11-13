<?php

declare(strict_types=1);

namespace App\Driver\Api;

use App\Entity\DriverApiSession;
use App\Repository\DriverApiSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Where a signed-in driver's admin API tokens live (ADR-074): a driver_api_session row found through a
 * random key in the driver's PHP session. The browser never sees the tokens.
 */
final class DriverTokenStore
{
    public const SESSION_KEY = '_driver_api_key';

    private ?DriverApiSession $current = null;
    private bool $loaded = false;

    public function __construct(
        private readonly RequestStack $requests,
        private readonly DriverApiSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%/var/lock')]
        private readonly string $lockDir = '',
    ) {}

    /** The signed-in driver's row, or null when this browser session has none (or it was removed). */
    public function current(): ?DriverApiSession
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $key = $this->session()?->get(self::SESSION_KEY);
            $this->current = \is_string($key) && $key !== '' ? $this->sessions->findOneByKeyHash(self::hash($key)) : null;
        }

        return $this->current;
    }

    /**
     * After a successful login: one new row and a new key in a fresh session id.
     *
     * @param array<string, mixed> $driver
     */
    public function start(string $accessToken, string $refreshToken, int $expiresIn, array $driver, string $deviceId): DriverApiSession
    {
        $this->end();
        $key = bin2hex(random_bytes(32));
        $row = (new DriverApiSession(self::hash($key), $driver, $deviceId))
            ->setTokens($accessToken, $refreshToken, $expiresIn, new \DateTimeImmutable());
        $this->em->persist($row);
        $this->em->flush();

        $session = $this->session();
        $session?->migrate(true);
        $session?->set(self::SESSION_KEY, $key);
        $this->current = $row;
        $this->loaded = true;

        return $row;
    }

    /**
     * Renews the access token once for every request that found it expired at the same moment: requests for
     * this row take turns (a file lock), re-read it, and refresh only if no other request has done so since
     * $usedAccessToken was sent. The lock is a file, not the database: SQLite has a single writer, and holding
     * its write lock through the call to Dispatch made every other request fail with "database is locked".
     *
     * @param callable(string $refreshToken): array{access_token: string, refresh_token: string, expires_in?: int, driver?: array<string, mixed>|null} $refresh
     */
    public function refresh(DriverApiSession $row, string $usedAccessToken, callable $refresh): DriverApiSession
    {
        $lock = $this->lock((int) $row->getId());
        try {
            $row = $this->sessions->reload($row);
            if ($row->getAccessToken() !== $usedAccessToken) {
                return $row;
            }
            $tokens = $refresh($row->getRefreshToken());
            $row->setTokens($tokens['access_token'], $tokens['refresh_token'], (int) ($tokens['expires_in'] ?? 900), new \DateTimeImmutable());
            if (\is_array($tokens['driver'] ?? null)) {
                $row->setDriver($tokens['driver']);
            }
            $this->em->flush();

            return $row;
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource an exclusive lock on this session row, held until released */
    private function lock(int $id)
    {
        $dir = $this->lockDir !== '' ? $this->lockDir : sys_get_temp_dir() . '/fvst-driver-lock';
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the lock directory ' . $dir);
        }
        $handle = fopen($dir . '/driver-refresh-' . $id . '.lock', 'c');
        if ($handle === false || !flock($handle, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the driver session for a token refresh.');
        }

        return $handle;
    }

    /** @param array<string, mixed> $driver */
    public function updateDriver(array $driver): void
    {
        $row = $this->current();
        if ($row !== null) {
            $row->setDriver($driver);
            $this->em->flush();
        }
    }

    public function touch(DriverApiSession $row): void
    {
        $now = new \DateTimeImmutable();
        // At most once a minute: every page makes several calls.
        if ($now->getTimestamp() - $row->getLastUsedAt()->getTimestamp() >= 60) {
            $row->touch($now);
            $this->em->flush();
        }
    }

    /** Forgets the tokens (logout, or the admin API refused to renew them). */
    public function end(): void
    {
        $row = $this->current();
        if ($row !== null) {
            $this->em->remove($row);
            $this->em->flush();
        }
        $this->session()?->remove(self::SESSION_KEY);
        $this->current = null;
        $this->loaded = true;
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        $request = $this->requests->getMainRequest();

        return $request !== null && $request->hasSession() ? $request->getSession() : null;
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
