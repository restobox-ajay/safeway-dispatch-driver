<?php

declare(strict_types=1);

namespace App\Htaccess;

use App\Entity\Config;
use App\Service\ConfigService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps the Htaccess Lock policy (stored in the `config` table) and the managed block in the real
 * `.htaccess` in step. The file is the thing that enforces; config is the source of truth the admin
 * form reads back.
 */
class HtaccessLockManager
{
    public const KEY_ENABLED = 'htaccess_lock.enabled';
    public const KEY_IPS = 'htaccess_lock.ips';
    public const KEY_EXEMPT_PATHS = 'htaccess_lock.exempt_paths';
    public const KEY_STATUS = 'htaccess_lock.status_code';
    public const KEY_ERROR_FILE = 'htaccess_lock.error_file';
    public const KEY_LAST_TEST = 'htaccess_lock.last_test';

    public function __construct(
        private readonly ConfigService $config,
        private readonly HtaccessFile $file,
        private readonly HtaccessLockRenderer $renderer,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function file(): HtaccessFile
    {
        return $this->file;
    }

    /**
     * Read straight from the database, not through ConfigService's Doctrine identity map: a change re-reads the
     * policy after waiting for {@see HtaccessLockMutex}, and an entity loaded earlier in the same request or
     * process would still hold the value from before the other change was committed (issue #44).
     */
    public function settings(): HtaccessLockSettings
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT config_key, config_value FROM config WHERE config_key IN (?)',
            [[self::KEY_ENABLED, self::KEY_IPS, self::KEY_EXEMPT_PATHS, self::KEY_STATUS, self::KEY_ERROR_FILE]],
            [ArrayParameterType::STRING],
        );

        return new HtaccessLockSettings(
            \in_array($rows[self::KEY_ENABLED] ?? '', ['1', 'true', 'yes', 'on'], true),
            $this->splitLines($rows[self::KEY_IPS] ?? ''),
            $this->splitLines($rows[self::KEY_EXEMPT_PATHS] ?? ''),
            isset($rows[self::KEY_STATUS]) ? (int) $rows[self::KEY_STATUS] : HtaccessLockSettings::STATUS_NOT_FOUND,
            $rows[self::KEY_ERROR_FILE] ?? '',
        );
    }

    /** Apply to the file FIRST: if it cannot be written, nothing is recorded as enabled. */
    public function save(HtaccessLockSettings $settings): void
    {
        if ($settings->enabled) {
            $this->file->writeBlock($this->renderer->render($settings));
        } else {
            $this->file->removeBlock();
        }

        $rows = [
            self::KEY_ENABLED => $settings->enabled ? '1' : '0',
            self::KEY_IPS => implode("\n", $settings->ips),
            self::KEY_EXEMPT_PATHS => implode("\n", $settings->exemptPaths),
            self::KEY_STATUS => (string) $settings->statusCode,
            self::KEY_ERROR_FILE => $settings->errorFile,
        ];

        // The five rows are one policy: commit them together, so no reader sees half of a change (issue #44). And
        // write them directly, not through ConfigService: Doctrine only issues an UPDATE when a value differs from
        // the one it LOADED, so a row this process had read before another change moved it would be skipped —
        // e.g. "disable" after a concurrent enable wrote nothing, leaving the config saying enabled.
        $this->connection->transactional(function (Connection $connection) use ($rows): void {
            foreach ($rows as $key => $value) {
                $updated = $connection->executeStatement('UPDATE config SET config_value = ? WHERE config_key = ?', [$value, $key]);
                if ($updated === 0) {
                    $connection->insert('config', ['config_key' => $key, 'config_value' => $value]);
                }
            }
        });

        // Bring any of these rows this process already holds as entities up to date, so a later read through
        // ConfigService in the same request sees what was just stored.
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[Config::class] ?? [] as $entity) {
            if ($entity instanceof Config && \array_key_exists($entity->getKey(), $rows)) {
                $this->em->refresh($entity);
            }
        }
    }

    /**
     * @return array{exists:bool,writable:bool,hasBlock:bool,inSync:bool,error:?string}
     */
    public function fileState(): array
    {
        $enabled = $this->settings()->enabled;

        try {
            $hasBlock = $this->file->hasBlock();
            $inSync = $enabled
                ? $this->file->currentBlock() === $this->renderer->render($this->settings())
                : !$hasBlock;
            $error = null;
        } catch (\RuntimeException $e) {
            $hasBlock = false;
            $inSync = false;
            $error = $e->getMessage();
        }

        return [
            'exists' => $this->file->exists(),
            'writable' => $this->file->isWritable(),
            'hasBlock' => $hasBlock,
            'inSync' => $inSync,
            'error' => $error,
        ];
    }

    public function lastTest(): ?HtaccessLockSelfTestReport
    {
        $raw = $this->config->getString(self::KEY_LAST_TEST);
        if ($raw === '') {
            return null;
        }
        $data = json_decode($raw, true);

        return \is_array($data) ? HtaccessLockSelfTestReport::fromArray($data) : null;
    }

    public function storeLastTest(HtaccessLockSelfTestReport $report): void
    {
        $this->config->set(self::KEY_LAST_TEST, json_encode($report->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function splitLines(string $value): array
    {
        return $value === '' ? [] : array_values(array_filter(explode("\n", $value), static fn (string $l): bool => $l !== ''));
    }
}
