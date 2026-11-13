<?php

declare(strict_types=1);

namespace App\Htaccess;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched by {@see HtaccessLockGate} exactly once per applied change — the single hook point for anything
 * that should react to the lock changing (notifications, webhooks, …), whichever adapter (web form, API,
 * console) made the change.
 */
final class HtaccessLockChangedEvent extends Event
{
    public function __construct(
        public readonly string $action,
        public readonly HtaccessLockSettings $before,
        public readonly HtaccessLockSettings $after,
        public readonly HtaccessLockActor $actor,
    ) {
    }
}
